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
- **`Strategies/Entry/BounceStrategy`** — multi-candle Price Action pattern detector (~20-25 candles lookback) with EMA/MACD confirmation:
  1. *Breakout / Impulse Peak*: checks prior move beyond level ($\ge 0.35 \times \text{ATR}$).
  2. *Pullback to level*: price returns into level zone ($\le 0.25 \times \text{ATR}$) and holds ($< 0.40 \times \text{ATR}$ penetration).
  3. *Compression*: volume/range deceleration near level.
  4. *Impulse Bounce*: trigger candle body $\ge 0.35 \times \text{ATR}$ in the direction of the trade within $0.50 \times \text{ATR}$ entry zone.
  5. *Trend Alignment (EMA 50)*: entry direction must align with the global trend — price must be beyond EMA 50 + $0.10 \times \text{ATR}$ buffer AND EMA 50 slope must confirm direction (rising for LONG, falling for SHORT over last 3 bars).
  6. *Volume Confirmation*: trigger candle volume must be $> 1.1 \times$ average volume of the pullback.
  7. *Momentum Exhaustion*: pullback phase must not contain massive momentum candles ($> 0.7 \times \text{ATR}$).
  8. *Wick Rejection / Pin Bar*: trigger candle (or previous) must pierce/touch the level (within $0.15 \times \text{ATR}$) and show rejection.
  9. *Stop-loss*: placed beyond the pullback swing extrema.
  10. *MACD Alignment*: MACD histogram must be in the direction of the trade (positive for LONG, negative for SHORT).
- **Active Entry Strategies Status**: Currently only `BounceStrategy` is registered as active in `TradingAgent::__construct()`. The other 3 strategies (`RetestStrategy`, `FalseBreakoutStrategy`, `TrendPullbackStrategy`) are temporarily disabled.
- **Strategy Statistics & Diagnostics Logging**:
  - `BounceStrategy::diagnose()` scores 12 individual criteria (prior impulse, pullback touch, level held, compression, impulse trigger, entry zone, trend alignment, volume confirmation, momentum exhaustion, wick rejection, R:R, MACD alignment).
  - All evaluations reaching $\ge 50\%$ criteria match are logged into `strategy_evaluations` table via `StrategyLoggerInterface` / `DatabaseStrategyLogger`.
  - Records include completion score %, status (`completed` for 100% / `partial` for 50-99%), exact values vs expected thresholds, human-readable `missing_criteria` list, initial chart (`chart_path`), and follow-up outcome chart (`outcome_chart_path` rendered after 30 candles via `strategy:render-outcomes` scheduler command).
  - Filament Resource: `StrategyEvaluationResource` provides interactive table, tabs ('Все ≥50%', 'Вход 100%', 'Близко ≥70%', 'Частичные'), filters, criteria checklist modal, dual chart view (at setup vs outcome +30 candles), and `StrategyStatsOverview` widget.
  - Data Retention & Pruning: `strategy:prune` daily scheduler command deletes evaluations, chart images, and specs older than 7 days (`TRADING_RETENTION_DAYS=7`).


