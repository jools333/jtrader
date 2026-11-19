# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Laravel 12 + Filament 4 app that pulls BingX crypto market data and analyses pairs
(ATR, support/resistance levels, trend, chart figures). The UI is a Filament page
rendering candlesticks + volume via TradingView `lightweight-charts`. A future trading
module is intended to consume `MarketAnalyzerInterface`.

## Running everything is Docker-based

The app runs in containers (`docker-compose.yml`): `app` (PHP-FPM, `serversideup/php:8.4-fpm`),
`web` (Nginx → http://localhost:8080), `db` (MariaDB), `redis`, `queue`, `ws` (WebSocket
candle listener), and `node` (Vite, only under `--profile dev`).

```bash
make build          # build the app image (passes host UID/GID for bind-mount perms)
make up             # start the stack
make artisan c="migrate"
make test           # php artisan test inside the container
make sh             # shell into the app container
```

Run artisan/composer/tests **inside the container** (`docker compose exec app …` or the
`make` targets), not on the host. Admin login: `admin@jtrader.local` / `password` at `/admin`.

Run a single test: `docker compose exec app php artisan test --filter=MarketDashboardTest`.

## Deployment & CI/CD

Deployment to the remote server (`root@jools.com.ru:/home/jools/jtrader`) is fully automated via GitHub Actions CI/CD.
**CRITICAL RULE: NEVER run manual deploy commands, docker compose up, docker build, or deploy scripts via SSH on root@jools.com.ru.**
- It is strictly sufficient to **commit and push changes to git (`main` branch)**.
- GitHub Actions automatically pulls, builds, runs migrations, and restarts services. Manual deployment commands cause race conditions, duplicate builds, and container conflicts.

## Architecture (the parts that span multiple files)

Everything market-related lives under `app/Market/` behind two interfaces, both bound in
`app/Providers/ExchangeServiceProvider.php`:

- **`Contracts/ExchangeInterface`** — raw exchange access (`symbols/klines/ticker`).
  The only BingX-aware code is `Exchange/BingX/BingXExchange` (public swap v3 endpoints,
  no API key). To support another exchange: add an implementation and a `match` arm in the
  provider; the driver is selected by `config('exchange.default')`. Pairs and timeframes
  are also in `config/exchange.php` — never hard-code them.

- **`Contracts/MarketAnalyzerInterface`** — `atr() / levels() / trend() / patterns()`.
  Implemented by `Analysis/MarketAnalyzer`, which reads candles via `CandleRepository`
  (never calls the exchange directly) and delegates math to `Analysis/Support/`
  (`SeriesMath` = ATR/ADX/regression/pivots, `PatternDetector` = H&S, double top/bottom,
  triangles built on a zig-zag of swing pivots). Results are returned as DTOs in
  `Market/DTO/` with `toArray()` for the UI.

**Candle storage & data flow.** Candles are persisted (`candles` table, `App\Models\Candle`
Eloquent model — distinct from the `Market\DTO\Candle` value object). `CandleRepository`
reads DTOs oldest→newest and **lazy-syncs from the exchange when the store is empty**;
that sync is wrapped so network failures degrade to stored data rather than breaking reads.
Populate the store via the `ws` container (`candles:ws` command) which first seeds
historical data via the REST API then streams live ticks over a persistent BingX WebSocket
connection (`wss://open-api.bingx.com/market`), auto-reconnecting on failure.  `candles:sync`
remains available for manual one-off pulls; `candles:import` covers the offline seed case
(from `storage/app/seed/SYMBOL__INTERVAL.json`).

**Dashboard.** `app/Filament/Pages/MarketDashboard.php` +
`resources/views/filament/pages/market-dashboard.blade.php`. The Blade view is an Alpine
component; it pulls all chart data and overlays from the server by calling the Livewire
method `marketData(symbol, interval)` via `$wire.marketData(...)` — there is no JSON API
route. `lightweight-charts` is self-hosted via a `<script>` from
`public/vendor/lightweight-charts/` (not bundled, not a CDN). Overlay toggles
(Уровни / Фигуры / ATR) re-apply price lines / line series client-side from that one payload.
`marketData()` sanitises symbol/interval against config and wraps `ticker()` so a live-call
failure never breaks the page.

## Environment quirk that will waste your time if unknown

In this dev sandbox the network is asymmetric (this is environmental, not how production
behaves). **It can also change between sessions — always test reachability empirically
(`Http::get(...)` or `candles:sync` from inside the container) instead of assuming.**

- **Containers DO have outbound internet** (re-verified 2026-06-22): from the `app` container,
  BingX is reachable (`Http::get('https://open-api.bingx.com/...')` → 200) and `candles:sync`
  pulls live candles, so the offline seed bridge is optional. (Earlier this was blocked except
  Docker Hub; if it regresses, fall back to the host-fetch → `candles:import` bridge below.)
- **`composer` still runs on the host** (PHP 8.5) and the bind-mounted `vendor/` is used by the
  container (PHP 8.4) — this is about the toolchain/PHP version split, independent of network.
- **Offline candle bridge (fallback if container egress is blocked):** fetch on the host into
  `storage/app/seed/SYMBOL__INTERVAL.json`, then `candles:import` inside the container.
- **Host → published container ports is broken** (MariaDB handshake errno 11; IPv6 on :8080).
  Verify HTTP from inside the network: `docker compose exec web wget -qO- http://localhost/...`.
- `docker compose exec` and container→DB both work fine.

The image has `python3`, `python3-matplotlib`, and `ext-intl` installed (see `docker/php/Dockerfile`).
`gd` and `bcmath` are absent but not required. `ext-intl` is needed by Laravel's `Number` helper
and Filament pagination — it is compiled in the Dockerfile via `docker-php-ext-install intl`.

## Trading Module & Entry Strategies

Trading logic is located in `app/Trading/`:
- **`Contracts/TradingAgentInterface`** / **`Agent/TradingAgent`** — evaluates market state for entries and exits. Requires $\ge 50$ candles in evaluation window. Two scheduled scans run in parallel: `agent:scan --interval=1h` (every minute) and `agent:scan --interval=4h` (every 5 minutes).
- **Active Parallel Strategies (2026-11-19)**:
  1. **`Strategies/Entry/LevelRetestStrategy`** (`SignalType::LevelRetest`, 1h, "пробой диапазона + ретест", метод Артёма Звездина):
     - *Range (`range_width`)*: последние 12 закрытых свечей образуют базу; ширина $1.5\text{–}4.5 \times \text{ATR}$.
     - *Break Confirmation (`break_confirmation`)*: закрытие за уровнем не дальше $0.8 \times \text{ATR}$, тело в сторону сделки.
     - *Trend Alignment (`trend_alignment`)*: локальный тренд 1H согласован (`EMA8 > EMA21` для LONG, зеркально для SHORT).
     - *4H Macro Trend (`level_retest_htf_filter_enabled=true`)*: цена выше 4H EMA50 для LONG (ниже 4H EMA50 для SHORT). Блокирует контртрендовые сделки против старшего таймфрейма.
     - *Structure Hold (`structure_hold`)*: удержание уровня (свечи не закрываются глубже $0.35 \times \text{ATR}$).
     - *Retest (`retest`)*: возврат к уровню в зону $0.25 \times \text{ATR}$ и удержание за ним.
     - *Risk Window (`risk_window`)*: стоп за экстремум структуры с буфером $0.15 \times \text{ATR}$. **Минимальный стоп ужесточён до $\ge 1.00\%$** (`level_retest_min_stop_pct=1.00`, отсекает шумные микро-стопы вместо их раздувания); потолок $1.80\%$.
     - *Exit*: полная фиксация на **2R** (`TAKE_PROFIT_MARKET` + `STOP_MARKET` на бирже) + тайм-стоп 16 часов (`TRADING_LEVEL_RETEST_MAX_HOLD_HOURS`). Без BE и трейлинга (они резали матожидание).
     - *Portfolio Limits*: максимум **1** одновременная позиция, 1 в одном направлении.

  2. **`Strategies/Entry/SqueezeBreakoutStrategy`** (`SignalType::SqueezeBreakout`, 4h, "пробой сжатия полос Боллинджера"):
     - *Volatility Squeeze (`volatility_squeeze`)*: сжатие диапазона — ширина полос Боллинджера (BB 20, 2.0 std) за последние 3 свечи опускалась ниже 35-го перцентиля за 50 свечей.
     - *Breakout (`bb_breakout`)*: закрытие сигнальной 4H свечи за верхней (LONG) или нижней (SHORT) полосой Боллинджера.
     - *Volume Surge (`volume_confirmation`)*: объем на пробойной свече $\ge 1.30 \times \text{SMA20}$ объема.
     - *Trend Alignment (`trend_alignment`)*: закрытие выше 4H EMA50 для LONG (ниже для SHORT).
     - *Risk Window (`risk_window`)*: начальный стоп ставится на расстоянии $2.0 \times \text{ATR}$ от цены входа (допустимое окно стопа $0.40\%–10.00\%$).
     - *Exit — Chandelier ATR Trailing Stop*: стратегия **не имеет жесткого тейк-профита** (`target1=0.0`), чтобы давать прибыли течь по тренду. Динамический трейлинг `PositionManager::manageChandelierTrailing` подтягивает стоп на бирже на расстоянии $3.5 \times \text{ATR}$ от лучшей достигнутой цены (MFE). Тайм-стоп 480 часов (20 дней).
     - *Position Sizing & Limits*: риск на сделку **0.05%** от баланса (~$45 per 1R на балансе 90,000 VST, не перегружает маржу при широких 4H стопах). Максимум **2** параллельные позиции.
     - *Бэктест (8.5 мес., все 8 пар)*: +63.4R, PF 1.52, все 8 инструментов и все 3 временных сплита закрылись в плюс. На периоде октябрь–ноябрь 2026: +13.9R (+$625) против -$125 у LevelRetest.
- **`Strategies/Entry/BounceStrategy` (ОТКЛЮЧЕНА)** — легаси-детектор отскоков, оставлен в коде только для регрессионных тестов. Причина отключения: за 32 дня 144 сделки, 82% выходов по стопу, чистыми **−$618** (см. «Performance History»), микро-отскоки от ближайшего уровня не покрывали комиссии. Ниже — его исторические критерии:
  - **Hard Filters (100% strictly mandatory risk safeguards, failure blocks entry immediately)**:
    1. *Level Approach (`level_approach`)*: local extreme within $[L - 0.85 \times \text{ATR}, L + 0.85 \times \text{ATR}]$. Price MUST actually test the support/resistance level.
    2. *Entry Zone (`entry_zone`)*: entry close within $0.65 \times \text{ATR}$ of key level. Prevents chasing price after it already dumped to the floor or pumped to the peak.
    3. *Directional Confirmation Candle (`bullish_confirmation` / `bearish_confirmation`)*: trigger candle matches direction (Close >= Open for LONG, Close <= Open for SHORT) or EMA8 confirms. Prevents shorting on green candles or longing on red candles.
    4. *Normal Volatility (`normal_atr`)*: ATR $> 0.20\%$ of current price (prevents entries in dead flat markets).
    5. *Anti-Climax Breakdown Protection (`no_climax`)*: no breakdown climax candle ($V \ge 2.2 \times V_{\text{avg}}$ with body $\ge 0.40 \times \text{ATR}$ closing at the extreme) in the approach.
    6. *Anti-Chasing Protection (`no_chasing`)*: trigger candle body $< 0.60 \times \text{ATR}$.
    7. *Volume Surge (`volume_surge`)*: trigger candle volume $\ge 1.05 \times V_{\text{avg}}$.
    8. *Trend Alignment (`strict_trend`)*: EMA8 slope confirms direction (rising for LONG, falling for SHORT) AND price beyond EMA50. Completely eliminates counter-trend knife-catching and top-shorting into strong momentum.
  - **Soft Price Action Criterion (1 scored condition)**:
    1. *ATR Bounce (`atr_bounce`)*: bounce $\ge 0.10 \times \text{ATR}$ from local extreme.
  - *Entry Rule*: All 8 Hard Filters must pass AND total score $\ge \text{min\_entry\_score}$ (default 80.0% = at least 8/9 criteria).
- **BTC Anchor & Intermarket Confirmation (`EntryGuard`)**:
  - `BTC-USDT`, `ADA-USDT`, and `XRP-USDT` are excluded from opening positions (`config('trading.excluded_symbols')`), but stream via WebSocket for market regime analysis. ADA was excluded after generating 85% of net losses during dead-zone chop around $0.25; XRP was excluded on 10.11.2026 after 10 consecutive stop losses (-$183 net).
  - For `LEVEL_RETEST` and `SQUEEZE_BREAKOUT` signals, only the macro regime gate applies: LONG is blocked when `BTC price < BTC EMA50` AND `BTC EMA8 < BTC EMA21`; SHORT is blocked when `BTC price > BTC EMA50` AND `BTC EMA8 > BTC EMA21`. Additionally, SqueezeBreakout requires BTC price above its 4H EMA50 for LONG (below for SHORT).
  - The 5m-calibrated filters (max ATR travel from the level, BTC storm width, BTC 3-bar delta, BTC/alt 1h HTF regimes) are **skipped for `LEVEL_RETEST` and `SQUEEZE_BREAKOUT`**: they are tuned for 5m scalping and on 1h/4h they would reject valid setups. They still apply to legacy signal types.
- **BTC Lead-Lag Fast Exit (`EarlyReversalStrategy`, `ExitReason::BtcReversal`)**:
  - Detects sharp BTC counter-impulses ($\ge 0.35\%$ drop for LONG or $\ge 0.35\%$ pump for SHORT over 2 bars) and executes immediate market exit on altcoins before they follow BTC down. Disabled by default.
- **Execution & Position Lifecycle Safeguards (`PositionManager`, `BingXTradeExecutor`, `BingXPositionSyncService`)**:
  - *Orphan Order Cleanup*: `cancelAllOrders(symbol)` called on 100% position close and before opening new positions.
  - *Single Position per Symbol*: prevents accumulating duplicate legs across intervals.
  - *Max Open Positions*: `TRADING_MAX_OPEN_POSITIONS=3` global cap across all symbols. Per-strategy limits: `strategy_max_open_positions` (LevelRetest: 1, SqueezeBreakout: 2).
  - *Directional Cap*: `strategy_max_positions_per_direction` (LevelRetest: 1, SqueezeBreakout: 2) prevents correlated basket liquidation.
  - *Entry Stagger*: `TRADING_MIN_ENTRY_INTERVAL_MINUTES=10` enforces at least 10 minutes between opening ANY positions across all symbols, preventing 30-second cluster entries on the same 1-minute impulse bar.
  - *Cooldown*: `TRADING_ENTRY_COOLDOWN_MINUTES=45` prevents re-entering the same symbol for 45 minutes after close.
  - *Extended Stop-Loss Cooldown*: `TRADING_STOP_LOSS_COOLDOWN_MINUTES=90` enforces 90-minute pause on a symbol after a Stop Loss exit to avoid re-entering into ongoing hostile breakouts.
  - *Daily Loss Circuit Breaker & Lockout*: `TRADING_DAILY_LOSS_LIMIT=150.0` pauses opening new positions if net closed loss reaches -150 USDT. `TRADING_DAILY_LOSS_LOCKOUT_HOURS=8` prevents midnight calendar reset (00:00 MSK) from prematurely unblocking trading after a late evening drawdown. Sends a single Telegram alert.
  - *Risk & Position Sizing Caps*:
    - `max_position_pct`: Default notional cap lowered to **3.5%** (was 5.0%), capping position size at ~3,150 USDT on 90,000 VST balance.
    - SqueezeBreakout uses `strategy_risk_pct.SQUEEZE_BREAKOUT=0.05%` (~$45 risk per 1R) to prevent margin overload on wide 4H stops.
    - `max_stop_percent`: Hard stop-loss ceiling **1.8%** for LevelRetest, **10.0%** for 4H SqueezeBreakout.
    - `symbol_max_position_pct`: DOGE capped at 2.5% notional to restrict drawdown on noisy meme wicks.
  - *Pending Limit Order Sync Protection*: 3-minute grace period with resting order inspection prevents prematurely marking unfilled limit entries as closed.
  - *Trailing & Profit Protection*:
    - `LEVEL_RETEST`: dynamic protection is **skipped** (`dynamic_protection_skip_signals`); position is held to 2R target or 16h time-stop.
    - `SQUEEZE_BREAKOUT`: managed via `manageChandelierTrailing()` (ATR 3.5 trailing stop behind best price MFE, no fixed TP cap).
    - Legacy signals: optional break-even at +0.38% and trailing at +0.60%.
  - *Exit Reason Price-Based Inference & Sync Protection (`BingXPositionSyncService::inferExitReasonFromPrice`)*:
    - Automatically classifies fills into `take_profit_hit`, `stop_loss_hit`, `trailing_stop`, or `break_even` by comparing fill price against level brackets.
- **Active Strategies Status (2026-11-19)**:
  - **ENTRY**: Две параллельные стратегии:
    1. `LevelRetestStrategy` (`LEVEL_RETEST`, 1h, min stop 1.0%, 4H EMA50 filter, TP 2R, SL structural extreme, max 1 pos).
    2. `SqueezeBreakoutStrategy` (`SQUEEZE_BREAKOUT`, 4h, BB Squeeze + Breakout + Volume 1.3x + 4H EMA50, Chandelier ATR 3.5 trail, max 2 pos).
  - **EXIT**:
    - `LevelRetest`: Bracket 2R на бирже + тайм-стоп 16h.
    - `SqueezeBreakout`: Динамический Chandelier Trailing Stop на бирже (3.5 ATR) + тайм-стоп 480h (20 дней).
  - `BounceStrategy`, `BtcLeadLagStrategy`, `RetestStrategy`, `FalseBreakoutStrategy`, `TrendPullbackStrategy`, `EarlyReversalStrategy` — отключены / legacy.
- **Real-Time WebSocket Impulse Trigger (`BtcImpulseDetector`)**:
  - `WsCandles` streams live `BTC-USDT@kline_1m` ticks via BingX WebSocket.
  - When `TRADING_LEAD_LAG_ENABLED=true`, monitors price moves and triggers altcoin scans. When disabled (`false`), returns immediately.
  - Fixed WebSocket Ping/Pong keep-alive to maintain 24/7 stable connection without timeouts.
- **Strategy Statistics & Diagnostics Logging**:
  - `LevelRetestStrategy::diagnose()` scores 6 criteria (range width, break confirmation, trend alignment, structure hold, retest, risk window); a full (100%) score opens the trade, partial scores are logged for review.
  - `BounceStrategy::diagnose()` scores 12 individual criteria (4 hard + 8 soft) — legacy, used by tests.
  - All evaluations reaching $\ge 50\%$ criteria match are logged into `strategy_evaluations` table via `StrategyLoggerInterface` / `DatabaseStrategyLogger`.
  - Records include completion score %, status (`completed` for 100% / `partial` for 50-99%), exact values vs expected thresholds, human-readable `missing_criteria` list, initial chart (`chart_path`), and follow-up outcome chart (`outcome_chart_path` rendered after 30 candles via `strategy:render-outcomes` scheduler command).
  - Filament Resource: `StrategyEvaluationResource` provides interactive table, tabs ('Все ≥50%', 'Вход 100%', 'Близко ≥70%', 'Частичные'), filters, criteria checklist modal, dual chart view (at setup vs outcome +30 candles), and `StrategyStatsOverview` widget.
  - Data Retention & Pruning: `strategy:prune` daily scheduler command deletes evaluations, chart images, and specs older than 3 days (`TRADING_RETENTION_DAYS=3`).

## Real Trade Statistics & Performance Verification via BingX API

**Always verify actual trading results, fills, win rate, and realized PnL via BingX API**, rather than relying solely on the local `positions` database table.

- **Why**: When positions open, `BingXTradeExecutor` attaches native exchange bracket orders (`takeProfit` / `stopLoss`). BingX executes TP/SL orders on the orderbook automatically. The exchange is the true source of truth for executions, fills, realized PnL, commissions, and funding fees.
- **BingX Private API Endpoints**:
  - Income & Realized PnL: `GET /openApi/swap/v2/user/income` (returns `REALIZED_PNL`, `TRADING_FEE`, `FUNDING_FEE` with timestamps and exact PnL).
  - All Orders / Fills: `GET /openApi/swap/v2/trade/allOrders` & `GET /openApi/swap/v2/trade/allFills`.
  - Open Positions: `GET /openApi/swap/v2/user/positions`.
  - Account Balance: `GET /openApi/swap/v2/user/balance`.
- **API Credentials**: Configured in `.env` (`EXCHANGE_BINGX_API_KEY`, `EXCHANGE_BINGX_API_SECRET`, `config('exchange.drivers.bingx')`). Outbound requests are signed with HMAC-SHA256.
- **How to inspect**: Run queries via Tinker or artisan command using `Http::baseUrl(...)` with `X-BX-APIKEY` header and timestamped HMAC signature.

## Checking Trade Statistics
Real trade statistics (positions, PnL) should be checked on the production server via SSH:
`ssh jools@jools.com.ru` -> `/home/jools/jtrader`

### Performance History (Verified on BingX VST API):
- **2026-08-31**: Realized PnL: +$203.60, Fees: -$129.69, Funding: +$0.58, **Net PnL: +$74.48** (LONG Net: +$41.08, SHORT Net: +$29.74).
- **2026-09-01**: Realized PnL: +$83.98, Fees: -$93.46, Funding: +$0.79, **Net PnL: -$8.69** (41 LONGs [-$25.63 net], 7 SHORTs [-$15.62 net]).
- **2026-09-02**:
  - *Automated Bot Trades*: **6/6 wins (100% Win Rate)**, Realized: +$37.90, Fees: -$18.82, Funding: -$0.23, **Net Bot Profit: +$18.85** (ADA: +$10.33, LINK: +$5.20, DOGE: +$3.55).
  - *Manual Close of Orphaned SOL-USDT*: -$149.81 net (opened Sept 01 15:32 before order cleanup fixes).
  - *Total Net (all included)*: -$130.96.
- **2026-09-03 – 2026-09-04**:
  - *Total Trades*: 12 trades, Realized PnL: -$69.90, Fees: -$41.39, **Net PnL: -$111.28**.
  - *BounceStrategy*: 4 trades, **3/4 wins (75% Win Rate)**, Realized: +$7.92, Fees: -$13.87, Net: -$5.94 (LINK: +$3.75, ETH: +$13.80, ADA: +$15.34, BNB: -$38.83).
  - *BtcLeadLagStrategy*: 8 trades, **0/8 wins net (0% Win Rate)**, Realized: -$77.82, Fees: -$27.52, Net: -$105.34 (failed due to simultaneous 6-long cascade at 00:30 MSK followed by sharp BTC reversal). Now completely disabled.
- **2026-09-05**:
  - *Total Trades*: 5 trades (early morning noise before deduplication fixes). Realized PnL: -$0.40, Fees: -$14.38, **Net PnL: -$12.57**.
- **2026-09-06**:
  - *Total Trades*: 4 trades, **3/4 wins (75% Win Rate)**, Realized: +$30.98, Fees: -$15.28, **Net PnL: +$15.70** (ADA: +$37.87, SOL: +$6.93, LINK: +$0.88, XRP: -$29.98). Verified positive net return with all commissions covered.
- **2026-09-07**:
  - *Total Trades*: 13 trades, **8/13 wins (61.5% Win Rate)**, Realized: +$162.42, Fees: -$47.90, **Net PnL: +$114.52** (XRP SHORT: +$50.14, SOL SHORT: +$28.58, SOL SHORT: +$24.62, DOGE SHORT: +$24.50, BNB SHORT: +$23.68, ADA SHORT: +$17.47, ETH SHORT: +$14.55, LINK SHORT: +$9.62, XRP SHORT: -$7.71, ADA LONG: -$7.33, LINK LONG: -$6.05, ADA SHORT: -$26.05, DOGE SHORT: -$31.50).
  - *SHORT Performance*: 11 trades, **8/11 wins (72.7% Win Rate)**, Net PnL: **+$127.90**.
  - *LONG Performance*: 2 trades, **0/2 wins (0% Win Rate)**, Net PnL: **-$13.38**.
- **2026-09-08**:
  - *Total Trades*: 29 trades (whipsaw / intraday reversal). Realized: -$148.84, Fees: -$96.99, **Net PnL: -$245.83** (morning profits +$150 erased by afternoon short squeeze cascade; night entries in ADA, LINK, XRP held overnight).
- **2026-09-09**:
  - *Total Trades*: 28 trades, **4/28 wins (14.3% Win Rate)**, Realized PnL: -$65.94, Fees: -$112.00, **Net PnL: -$177.94**.
  - *Morning Wins*: LINK SHORT: +$110.66, ADA SHORT: +$96.53, XRP SHORT: +$16.96, SOL LONG: +$7.34.
  - *Incident & Root Cause Analysis*:
    1. **Dual-Instance Race Condition**: The local dev container was running live `scheduler` and `ws` with `TRADING_EXECUTOR=bingx` concurrently with the remote server. Both bots were opening orders, canceling each other's SL/TP brackets via `cancelAllOrders`, and re-importing the other's positions as `EXTERNAL`. Local container stopped and forced to `TRADING_EXECUTOR=paper`.
    2. **Zero Target2 Bug on LONGs**: `BingXPositionSyncService` imported external positions with `'target2' => 0.0`. `Target2Strategy` had no `target2 > 0` check, evaluating `$price >= 0.0` as `true` on LONGs and instantly market-closing them within 60 seconds (costing -$61.01 in pure churn fees). Fixed with strict `target <= 0.0` guards in `Target1Strategy` and `Target2Strategy`.
- **2026-09-10**:
  - *Total Trades*: 15 trades, **5/15 wins (33.3% Win Rate)**, Realized: -$125.43, Fees: -$54.00, **Net PnL: -$179.42** (LONG: -$83.74, SHORT: -$95.68).
  - *Early Reversal Impact*: Catastrophic panic exits by EarlyReversal (`btc_reversal` / `ema_turn`) locked in -$118 net losses (LINK: -$39.23, LINK: -$42.40, XRP: -$36.43).
- **2026-09-11**:
  - *Total Trades*: 11 trades, **6/11 wins (54.5% Win Rate)**, Realized: -$3.21, Fees: -$39.78, **Net PnL: -$42.99** (LONG: -$38.52, SHORT: -$4.47).
  - *Afternoon Win Streak*: 3 consecutive TP1 hits (ADA: +$8.19, SOL: +$13.54, XRP: +$18.89).
  - *Issues*: Two massive EarlyReversal losses (BNB: -$45.74, ADA: -$37.09) wiped out all 6 wins due to inverted 1:2.5 R:R in quick mode; evening deployment of Maker PostOnly caused 24 rejected orders.
- **2026-09-15**:
  - *Total Trades*: 17 trades, **10/17 wins (58.8% Win Rate)**, Realized PnL: +$261.02, Fees: -$73.35, **Net PnL: +$187.67** (XRP SHORT: +$92.98, ETH SHORT: +$45.12, SOL SHORT: +$38.45). Account Equity reached all-time high of **90,639.49 VST**.
- **2026-09-16 – 2026-09-17**:
  - *Total Trades*: 20 trades, **3/20 wins (15.0% Win Rate)**, Net PnL: **-414.30 USDT** (Equity dropped to **90,237.73 VST**).
  - *Daytime (01:00 – 21:00 MSK)*: Stable controlled trading (-$44.65 net across 12 trades; LINK +$19.02, ETH +$18.43, LINK +$9.54).
  - *Incident & Root Cause Analysis*:
    1. **Synchronous 3-Long Cluster Cascade (21:08 MSK)**: A 1-minute BTC false bounce triggered simultaneous LONG entries on BNB (#516), XRP (#517), and ADA (#518) within 32 seconds under `max_open_positions=3`. Six minutes later, BTC dumped sharply and stopped out all 3 longs simultaneously, wiping out **-184.86 USDT** in 6 minutes.
    2. **Remote Daily Loss Limit Misconfiguration**: `.env` on remote server had `TRADING_DAILY_LOSS_LIMIT=300.0` instead of 150.0. Cumulative loss reached -294.63 USDT at 21:43 MSK (just $5.37 short of 300), so the circuit breaker failed to halt trading.
    3. **Midnight Calendar Day Reset**: At 00:00 MSK, the circuit breaker daily calculation reset to zero, allowing the bot to enter nighttime chop and lose an additional -$120 USDT across correlated shorts (ETH/SOL) and ADA long.
    4. **Remediation Implemented**: Enforced `TRADING_MAX_POSITIONS_PER_DIRECTION=1` (no multi-asset directional baskets), `TRADING_MIN_ENTRY_INTERVAL_MINUTES=10` (staggered entries), `TRADING_DAILY_LOSS_LIMIT=150.0`, and `TRADING_DAILY_LOSS_LOCKOUT_HOURS=8` (rolling window lockout).
- **2026-09-18 – 2026-09-24**:
  - *Account Equity*: **89,914.32 VST** (~0.8% drawdown from peak 90,639 VST).
  - *7-Day Verified Closes*: 56 closes, **28/56 wins (50.0% Win Rate)**.
  - *PnL Breakdown*: Realized PnL: **-$475.28 USDT**, Fees: **-$233.53 USDT**, **Net PnL: -$708.81 USDT**.
  - *Incident & Root Cause Analysis*:
    1. **Asymmetric Inverted R:R / Premature Micro-Win Clipping**:
       - Fixed position sizing capped by `max_position_pct` created uniform ~9,000 USDT notional trades.
       - Stop loss sat at 0.6%–1.2% (technical swing extreme + buffer), producing full stop losses of -$54 to -$108 USDT (plus ~$9 round-trip fees).
       - In contrast, Break-Even (`TRADING_BE_TRIGGER_PCT=0.25%`, buffer `0.05%`) and Trailing Stop (`TRADING_TRAILING_TRIGGER_PCT=0.40%`, distance `0.20%`) were set too tight, triggering inside 1-minute noise. Profitable trades were premature-stopped at +$0.50 to +$15.00 USDT, failing to offset stop-losses.
    2. **Missing `exit_reason` Diagnostic Blindspot**:
       - 80%+ of closed trades had `exit_reason = NULL` or `exchange_closed` because BingX reports relocated stop executions as `MARKET` fills without bracket metadata.
    3. **Remediation Implemented (2026-09-24)**:
       - Recalibrated Dynamic Protection: raised Break-Even trigger to **+0.60%** with a **+0.15%** buffer (ensuring clear net profit above fees), and Trailing Stop trigger to **+0.80%** with a **0.35%** trail distance. Updated both `.env` and `config/trading.php`.
       - Implemented Price-Based Exit Inference: added `inferExitReasonFromPrice()` in `BingXPositionSyncService` to accurately classify MARKET fills into `take_profit_hit`, `stop_loss_hit`, `trailing_stop`, or `break_even` by comparing fill price against level brackets.
       - Repaired Historical DB: ran `positions:backfill-exit-reason`, backfilling 346 positions with 0 NULL records remaining.
       - Rebuilt production config cache and restarted `queue`, `scheduler`, and `ws` containers.
- **2026-10-01 – 2026-10-04**:
  - *Calibration*: Lowered `min_entry_score` to 75%, volume to 0.90x, relaxed `strict_trend` to OR-logic, and conditioned BTC HTF on active momentum.
  - *Account Equity*: Grew from **89,767.04 VST** to **89,811.87 VST** (**+$44.83 VST net profit**, +0.05% portfolio return).
  - *Verified Trades*: 3 trades, **3/3 wins (100% Win Rate)**:
    - #585 SOL-USDT SHORT (01.10 23:29 MSK): 50% TP1 (+14.06) + remaining BE (-1.49), Net: **+$9.50 USDT**.
    - #586 LINK-USDT SHORT (02.10 10:07 MSK): 50% TP1 (+9.60) + 50% Trailing (+10.92), Net: **+$17.39 USDT**.
    - #587 XRP-USDT LONG (02.10 23:52 MSK): 100% TP1 (+21.11), Net: **+$17.96 USDT**.
  - *Total PnL*: Realized: **+$54.19 USDT**, Commissions: **-$9.43 USDT**, Funding: **+$0.07 USDT**, **Net PnL: +$44.83 USDT**.
  - *Weekend Note*: 0 trades on Oct 3–4 due to weekend low ATR and volume drops (<0.90x avg), with safety filters properly preventing choppy chop losses.
- **2026-10-05**:
  - *Account Equity*: **89,778.20 VST** (still net positive **+$11.16 VST** above pre-calibration baseline of 89,767.04 VST).
  - *Verified Trades*: 3 trades on Monday Oct 05, **0/3 wins**:
    - #588 ADA-USDT LONG (05.10 08:28 MSK): Stop Loss hit @ 0.2713 (loss: -$17.29, fee: -$3.14), Net: **-$20.43 USDT**.
    - #589 DOGE-USDT SHORT (05.10 13:34 MSK): Ultra-tight SL hit @ 0.0962 (+0.12% move, loss: -$2.81, fee: -$2.25), Net: **-$5.05 USDT**.
    - #590 XRP-USDT SHORT (05.10 17:34 MSK): Ultra-tight SL hit @ 1.4966 (+0.16% move, loss: -$5.05, fee: -$3.14), Net: **-$8.19 USDT**.
  - *Total PnL (Oct 05)*: Realized: **-$25.14 USDT**, Commissions: **-$8.53 USDT**, **Net PnL: -$33.67 USDT**.
  - *Risk Observations*: Micro-losses on DOGE (-$5.05) and XRP (-$8.19) revealed that ultra-tight stops (<0.20%) were getting suffocated by 1-minute noise. Implemented `min_stop_percent = 0.40%` floor, strict confirmation candle, and anti-momentum run protection.
- **2026-10-06 – 2026-10-07**:
  - *Account Equity*: **89,780.96 VST** (grew by **+2.76 VST**, total net profit since Oct 01 calibration is **+$13.92 VST**).
  - *Verified Trades (Oct 06)*: 2 trades, **1/2 wins (50.0% Win Rate)**:
    - #591 SOL-USDT LONG (06.10 14:20 MSK): 100% Take Profit hit @ 121.128 (+0.765% gain), Realized: **+$24.04 USDT**, Fees: **-$3.15 USDT**, **Net: +$20.89 USDT**. Clean bounce off 120.07 support with R:R 2.0.
    - #592 ADA-USDT LONG (06.10 20:49 MSK): Stop Loss hit @ 0.2712 (-0.477% move), Realized: **-$14.99 USDT**, Fees: **-$3.13 USDT**, **Net: -$18.12 USDT**. The stop respected the new `min_stop_percent` (0.456% distance). Price went up to +0.40% (0.2736) but did not reach BE trigger (+0.60%) before local volume sell-off.
  - *Day PnL (Oct 06)*: Realized: **+$9.05 USDT**, Commissions: **-$6.28 USDT**, **Net PnL: +$2.77 USDT / VST** (positive day!).
  - *Trade Frequency*: Exactly 2 trades executed on Oct 06, perfectly fulfilling the user's objective of 1–2 trades per day.
- **2026-10-08 – 2026-10-13**:
  - *Account Equity*: **89,697.81 VST** (-0.08% drawdown since Oct 01 baseline 89,767.04 VST; capital preserved, total drawdown <$80 across 2 weeks).
  - *Verified Trades (6 trades, 1/6 wins)*:
    - #593 XRP-USDT SHORT (09.10 18:56 MSK): Stop Loss hit @ 1.3865 (-0.28% move), MFE reached +0.25%, Net: **-$11.48 USDT**.
    - #594 ADA-USDT LONG (10.10 06:03 MSK): Stop Loss hit @ 0.2517 (-0.56% move, stopped in 6m during BTC 1H downtrend), Net: **-$23.90 USDT**.
    - #595 ADA-USDT LONG (10.10 19:17 MSK): Stop Loss hit @ 0.2538 (-0.47% move), MFE reached +0.27%, Net: **-$20.36 USDT**.
    - #596 ADA-USDT SHORT (10.10 22:28 MSK): Stop Loss hit @ 0.2534 (-0.47% move, whipsaw after longing at bottom), Net: **-$18.05 USDT**.
    - #597 ADA-USDT SHORT (12.10 13:26 MSK): Break-Even exit @ 0.2506 (+0.20% gain), Realized: **+$6.25 USDT**, Fees: **-$3.14 USDT**, Net: **+$3.12 USDT**.
    - #598 DOGE-USDT LONG (12.10 19:01 MSK): Stop Loss hit @ 0.0848 (-0.46% move during BTC 1H downtrend), Net: **-$12.47 USDT**.
  - *Root Cause Analysis*:
    1. **ADA-USDT Dead-Zone Drag**: ADA was responsible for **-$94.59 USDT** in net losses (1 win, 5 losses), while all other pairs combined (SOL, LINK, XRP, DOGE) produced **+$16.60 USDT** net profit. ADA got stuck in an ultra-tight, dead-zone compression around $0.25 where 5m horizontal levels are repeatedly violated.
    2. **Disabled Macro 1H Trend Filters**: With `btc_htf_filter_enabled=false` and `alt_htf_filter_enabled=false`, the bot longed ADA and DOGE into falling 1H BTC regimes.
    3. **Asymmetric TP/BE Hurdles in Low-Volatility Chop**: Target 1 at 2.0R and BE at +0.60% were too far for 5m bounces that typically deliver +0.30%–+0.50%, causing trades with +0.25%–+0.40% profit to slide back into stop losses.
  - *Remediation Implemented (2026-10-13)*:
    - Added `ADA-USDT` to `TRADING_EXCLUDED_SYMBOLS` alongside `BTC-USDT`.
    - Enabled Macro HTF Filters: `TRADING_BTC_HTF_FILTER_ENABLED=true` and `TRADING_ALT_HTF_FILTER_ENABLED=true`.
    - Recalibrated Take-Profit & Dynamic Protection: Target 1 lowered to **1.4R** (`min_rr=1.2`), Break-Even trigger lowered to **+0.38%** (buffer **+0.12%**), Trailing Stop trigger lowered to **+0.60%** (distance **0.25%**).
- **2026-10-15: BounceStrategy отключена, включена LevelRetestStrategy (28-дневный аудит + бэктест)**:
  - *Аудит 30 дней лайв-торговли (144 закрытые сделки: XRP/LINK/SOL/DOGE/ETH/BNB/ADA)*: Win rate **41.7%** (60/144), Realized **−$344.23**, комиссии **−$274.06**, **Net PnL −$618.29**.
  - *Разбор причин*: 82% сделок (118) закрылись по стопу и лишь 8 — по тейку; средний выигрыш $18.97 против среднего проигрыша −$20.91 (payoff < 1, значит при win rate 42% ожидание отрицательное); фактический R:R ≈ 0.9 против планового 1.4–2.6; LONG дали −$469.73 против −$148.56 у SHORT; ADA −$327.57 и ETH −$250.13.
  - *Вывод*: вход «микро-отскок на 0.10–0.20 ATR от ближайшего уровня» внутри 5m шума не имеет статистического перевеса, а комиссия 0.10% за круг при стопе 0.55% требует win rate > 47%.
  - *Бэктест нового подхода (28 дней истории BingX 5m/1h, 6 символов, проверка каждой половины выборки)*: фейды ложных пробоев, EMA-откаты и пробои без фильтра диапазона — все в минусе. Положительный результат только у «пробой диапазона + ретест» с фильтром ширины зоны $1.5–4.5 \times \text{ATR}$ на 1h: **n=80, WR 48%, +$951, PF 1.71**; первая половина +$825, вторая +$125 (обе прибыльные).
  - *Изменения*: активна только `LevelRetestStrategy` (1h), `agent:scan` переведён на `--interval=1h`; выход — полная фиксация 2R + тайм-стоп 16h; динамическая защита (BE/трейлинг) и частичные тейки для этого сигнала отключены; Bounce/BtcLeadLag/EarlyReversal/Target1/Target2 остаются в коде только для тестов.
- **2026-10-18 – 2026-11-19: Аудит 32 дней LevelRetest, запуск SqueezeBreakout на 4H**:
  - *Аудит лайв-торговли LevelRetest (42 закрытые сделки)*: Win rate **42.9%** (18/42), Gross PnL **+$5.73**, комиссии **−$131.59**, **Net PnL −$125.86**.
  - *Ключевые уязвимости*:
    1. **XRP-USDT drag**: 10 сделок из 10 закрылись в минус (**−$183.47 net**), в то время как остальные 5 инструментов дали **+$57.61 net**. 10.11.2026 XRP добавлен в `TRADING_EXCLUDED_SYMBOLS`.
    2. **Узкие стопы (<1.0–1.2%)**: на бэктесте 4.5 месяцев (349 сделок) сделки со стопом $<1.2\%$ принесли **−$1,221 net** (PF 0.68) из-за рыночного шума и давления комиссий; сделки со стопом $\ge 1.2\%$ принесли **+$1,319 net** (PF 1.57).
    3. **Контртрендовые сделки против 4H**: сделки против 4H EMA50 дали **−$464 net** (PF 0.80) против **+$562 net** (PF 1.25) по тренду 4H.
  - *Реализованные исправления LevelRetest*:
    - Поднят минимальный порог стопа `level_retest_min_stop_pct` с 0.40% до **1.00%**. Узкие стопы теперь жестко отклоняются, а не раздуваются искусственно.
    - Включен обязательный фильтр старшего таймфрейма `level_retest_htf_filter_enabled=true` (LONG только выше 4H EMA50, SHORT только ниже).
  - *Запуск второй параллельной стратегии — SqueezeBreakoutStrategy (4H)*:
    - Пробой сжатия полос Боллинджера (BB 20, 2.0 std <= 35th percentile) с фильтром объема ($\ge 1.3\text{x}$) и трендом 4H EMA50.
    - Выход по Chandelier ATR Trailing Stop (3.5 ATR) без фиксированного тейк-профита.
    - Риск на сделку 0.05% от депозита (~$45 per 1R).
    - Результат бэктеста за 8.5 месяцев (все 8 пар): **+63.4R, PF 1.52**, все 8 инструментов и все 3 сплита по времени в плюсе. На периоде октябрь–ноябрь 2026: +13.9R (+$625) против -$125 у LevelRetest.
  - *Архитектура исполнения*:
    - Глобальный лимит: 3 открытые позиции (`TRADING_MAX_OPEN_POSITIONS=3`).
    - Лимиты по стратегиям: LevelRetest max 1, SqueezeBreakout max 2.
    - Планировщик: сканирование 1h каждую минуту, сканирование 4h каждые 5 минут.
