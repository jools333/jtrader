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
- **`Contracts/TradingAgentInterface`** / **`Agent/TradingAgent`** — evaluates market state for entries and exits. Requires $\ge 50$ candles in evaluation window.
- **`Strategies/Entry/BounceStrategy`** — multi-candle Price Action pattern detector with dual-layer Hard/Soft criteria:
  - **Hard Filters (100% strictly mandatory risk safeguards)**:
    1. *Normal Volatility (`normal_atr`)*: ATR $> 0.20\%$ of current price (prevents entries in dead flat markets).
    2. *Anti-Climax Breakdown Protection (`no_climax`)*: no breakdown climax candle ($V \ge 2.2 \times V_{\text{avg}}$ with body $\ge 0.40 \times \text{ATR}$ closing at the extreme) in the approach.
  - **Soft Price Action, Trend & Volume Criteria (6 scored conditions)**:
    1. *Level Approach (`level_approach`)*: local extreme within $[L - 0.75 \times \text{ATR}, L + 0.75 \times \text{ATR}]$.
    2. *Entry Zone (`entry_zone`)*: entry close within $0.85 \times \text{ATR}$ of key level.
    3. *Trend Alignment (`strict_trend`)*: EMA8 slope confirms direction (rising for LONG, falling for SHORT) AND price beyond EMA50.
    4. *ATR Bounce (`atr_bounce`)*: bounce $\ge 0.10 \times \text{ATR}$ from local extreme.
    5. *Confirmation Candle (`bullish_confirmation` / `bearish_confirmation`)*: trigger candle matches direction or EMA8 confirms.
    6. *Volume Surge (`volume_surge`)*: trigger candle volume $\ge 1.15 \times V_{\text{avg}}$.
  - *Entry Rule*: Both Hard Filters must pass AND total score $\ge \text{min\_entry\_score}$ (default 75.0% = at least 6/8 criteria).
- **BTC Anchor & Intermarket Confirmation (`EntryGuard`)**:
  - `BTC-USDT` is excluded from opening positions (`config('trading.excluded_symbols')`), but streams via WebSocket for market regime analysis.
  - Altcoin LONG entries are blocked if BTC drops $> 0.20\%$ over 3 bars OR if BTC is in a macro downtrend (`BTC price < EMA50` AND `BTC EMA8 < EMA21`).
  - Altcoin SHORT entries are blocked if BTC pumps $> 0.20\%$ over 3 bars OR if BTC is in a macro uptrend (`BTC price > EMA50` AND `BTC EMA8 > EMA21`).
- **BTC Lead-Lag Fast Exit (`EarlyReversalStrategy`, `ExitReason::BtcReversal`)**:
  - Detects sharp BTC counter-impulses ($\ge 0.35\%$ drop for LONG or $\ge 0.35\%$ pump for SHORT over 2 bars) and executes immediate market exit on altcoins before they follow BTC down.
- **Execution & Position Lifecycle Safeguards (`PositionManager`, `BingXTradeExecutor`, `BingXPositionSyncService`)**:
  - *Orphan Order Cleanup*: `cancelAllOrders(symbol)` called on 100% position close and before opening new positions.
  - *Single Position per Symbol*: prevents accumulating duplicate legs across intervals.
  - *Cooldown*: `TRADING_ENTRY_COOLDOWN_MINUTES=30` prevents re-entering the same symbol for 30 minutes after close.
  - *Per-Symbol Risk & Quality Caps*:
    - `symbol_max_position_pct`: DOGE capped at 2.5% notional (vs 10% default) to restrict drawdown on noisy meme wicks.
    - `symbol_min_entry_score`: DOGE requires 80.0% score (vs 75% default), demanding at least 7/8 criteria match.
  - *Pending Limit Order Sync Protection*: 3-minute grace period with resting order inspection prevents prematurely marking unfilled limit entries as closed.
- **Active Entry Strategies Status**:
  - `BounceStrategy`: Multi-candle Price Action bounce setup from key horizontal levels with technical Stop Loss placed beyond support/resistance ($L \pm 0.25 \times \text{ATR}$) and calibrated Take Profit ($R:R \ge 2.0$, ensuring fees are well covered). The only active entry strategy.
  - `BtcLeadLagStrategy`: Cross-asset momentum spillover / lead-lag entry following BTC impulses. Disabled (`TRADING_LEAD_LAG_ENABLED=false`) due to correlation risk on multi-asset cascade entries during false BTC breakouts.
  - The other 3 strategies (`RetestStrategy`, `FalseBreakoutStrategy`, `TrendPullbackStrategy`) are temporarily disabled.
- **Real-Time WebSocket Impulse Trigger (`BtcImpulseDetector`)**:
  - `WsCandles` streams live `BTC-USDT@kline_1m` ticks via BingX WebSocket.
  - When `TRADING_LEAD_LAG_ENABLED=true`, monitors price moves and triggers altcoin scans. When disabled (`false`), returns immediately.
  - Fixed WebSocket Ping/Pong keep-alive to maintain 24/7 stable connection without timeouts.
- **Strategy Statistics & Diagnostics Logging**:
  - `BounceStrategy::diagnose()` scores 12 individual criteria (4 hard + 8 soft).
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
- **2026-09-08 (In Progress)**:
  - *Closed Trades*: 1 trade (DOGE SHORT hit SL: -$42.78 net).
  - *Open Trades (05:40 MSK)*: 3 positions (LINK SHORT +$25.41, ADA SHORT +$4.91, DOGE SHORT -$1.64 = **+$28.68 uPnL**).
  - *Account Balance / Equity*: **91,108.20 VST / 91,130.76 VST** (+92.20 VST net growth since 07.09 recalibration).

