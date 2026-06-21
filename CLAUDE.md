# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Laravel 12 + Filament 4 app that pulls BingX crypto market data and analyses pairs
(ATR, support/resistance levels, trend, chart figures). The UI is a Filament page
rendering candlesticks + volume via TradingView `lightweight-charts`. A future trading
module is intended to consume `MarketAnalyzerInterface`.

## Running everything is Docker-based

The app runs in containers (`docker-compose.yml`): `app` (PHP-FPM, `serversideup/php:8.4-fpm`),
`web` (Nginx → http://localhost:8080), `db` (MariaDB), `redis`, `queue`, `scheduler`,
and `node` (Vite, only under `--profile dev`).

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
Populate the store with `candles:sync` (live; scheduled every 30s via the `scheduler`
container, guarded by `withoutOverlapping`) or `candles:import` (offline, from
`storage/app/seed/SYMBOL__INTERVAL.json`).

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
behaves):

- **Containers have no internet except Docker Hub** — they cannot reach BingX/Packagist/GitHub.
  So `composer` runs on the **host** (PHP 8.5) and the bind-mounted `vendor/` is used by the
  container; live `candles:sync` does not work here (use the host-fetch → `candles:import` bridge).
- **Host → published container ports is broken** (MariaDB handshake errno 11; IPv6 on :8080).
  Verify HTTP from inside the network: `docker compose exec web wget -qO- http://localhost/...`.
- `docker compose exec` and container→DB both work fine.

The image lacks `intl/gd/bcmath` and cannot compile extensions (no apt mirror). This is fine —
no installed package hard-requires them (`vendor/composer/platform_check.php` only gates PHP
version). Do not write code paths that depend on `ext-intl` (e.g. Laravel's `Number` helper).
