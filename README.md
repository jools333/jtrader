# jtrader

Приложение на **Laravel 12 + Filament 4** для загрузки рыночных данных с криптобиржи
**BingX** и анализа криптовалютных пар: ATR, ценовые уровни, направление/сила тренда и
графические фигуры. В интерфейсе — японские свечи + объём (в стиле TradingView,
библиотека `lightweight-charts`) с переключаемыми оверлеями уровней, фигур и ATR.

Доступ к бирже изолирован за интерфейсом, поэтому BingX можно заменить другой биржей
без изменения остального кода.

---

## Стек

| Слой        | Технология                                  |
|-------------|---------------------------------------------|
| Backend     | PHP 8.4, Laravel 12                         |
| Admin / UI  | Filament 4                                  |
| График      | TradingView `lightweight-charts`            |
| БД          | MariaDB 11 (хранение свечей)                |
| Cache/Queue | Redis 7                                     |
| Web         | Nginx 1.27                                  |

---

## Docker-окружение

Сервисы (`docker-compose.yml`):

| Сервис      | Назначение                                              | Порт (host)     |
|-------------|---------------------------------------------------------|-----------------|
| `app`       | PHP-FPM (Laravel)                                        | —               |
| `web`       | Nginx, отдаёт `public/` и проксирует PHP                 | `8080`          |
| `db`        | MariaDB, хранит свечи и таблицы Filament/auth            | `3306`          |
| `redis`     | Кэш и очередь для фоновой синхронизации свечей           | —               |
| `queue`     | Worker очереди (`queue:work`)                            | —               |
| `scheduler` | Планировщик (`schedule:work`) — периодическая загрузка   | —               |
| `node`      | Vite dev-сервер (HMR), профиль `dev`                     | `5173`          |

### Быстрый старт

```bash
make build      # собрать образ app (UID/GID подставляются автоматически)
make up         # поднять стек
make artisan c="migrate"
# открыть http://localhost:8080
```

Полезные команды — см. `make help`.

---

## Главный архитектурный принцип

Весь рыночный код живёт в `app/Market/` за **двумя интерфейсами**:

```
ExchangeInterface          ← сырой доступ к бирже (symbols / klines / ticker)
MarketAnalyzerInterface    ← анализ (atr / levels / trend / patterns)
```

Это даёт две вещи:

1. **Сменить биржу** = написать новую реализацию `ExchangeInterface` + добавить одну ветку
   `match` в `ExchangeServiceProvider`. Остальной код не трогается.
2. **Анализ не зависит от биржи** — он читает свечи из БД (через `CandleRepository`),
   а не из сети.

Оба интерфейса связываются в `app/Providers/ExchangeServiceProvider.php`. Будущий торговый
модуль будет потреблять `MarketAnalyzerInterface`, ничего не зная о BingX.

---

## Карта кода: где что лежит

### Контракты — `app/Market/Contracts/`

- **`ExchangeInterface.php`** — абстракция биржи: `name()`, `symbols()`,
  `klines($symbol, $interval, $limit)` (массив DTO `Candle`, **старые → новые**),
  `ticker($symbol)` (нормализованный снимок: `last/high/low/volume/changePercent`).
- **`MarketAnalyzerInterface.php`** — поверхность ТА: `atr()`, `levels()`, `trend()`,
  `patterns()`. Именно это будет дёргать торговый модуль.

### Биржа — `app/Market/Exchange/BingX/BingXExchange.php`

Единственное место, знающее про BingX. Только **публичные swap-v3 эндпоинты** (без API-ключа):

- `klines()` → `/openApi/swap/v3/quote/klines`. BingX отдаёт новые→старые, поэтому в конце
  `usort` переворачивает в старые→новые; `closeTime` вычисляется из интервала.
- `ticker()` → `/openApi/swap/v2/quote/ticker`.
- `client()` ставит base_url, timeout, `retry(2, 200)`. При `code !== 0` → `RuntimeException`.

Конфиг драйвера приходит из `config/exchange.php` через провайдер.

### Анализ — `app/Market/Analysis/`

- **`MarketAnalyzer.php`** — реализация `MarketAnalyzerInterface`. Свечи берёт **только** из
  `CandleRepository`, никогда не ходит на биржу. Делегирует математику в `Support/`.
  - `atr()` — Wilder ATR за период.
  - `levels()` — свинг-пивоты → кластеризация по цене (`clusterPivots`, жадная 1-D с допуском
    `tolerance`) → ранжирование по score (касания + свежесть) → топ-N как поддержка/сопротивление
    относительно последней цены.
  - `trend()` — линрегрессия по close + ADX: малый наклон И ADX&lt;20 → боковик, иначе вверх/вниз
    по знаку наклона. Возвращает `TrendResult`.
  - `patterns()` — проксирует в `PatternDetector`.
- **`Support/SeriesMath.php`** — статические чистые функции: `linregSlope`, `rSquared`,
  `trueRanges`, `atr` (Wilder), `adx` (Wilder + DI), `pivots` (свинг-точки по окну left/right).
- **`Support/PatternDetector.php`** — детектор фигур. Работает по **зигзагу** чередующихся
  пивотов (устойчивее к шуму). Ловит: голова-и-плечи (+перевёрнутая), двойная вершина/дно,
  треугольники (восходящий/нисходящий/симметричный). Каждая фигура → DTO `Pattern` с
  точками-якорями для отрисовки и `confidence`.
- **`Support/Pivot.php`** — value-object свинг-точки (index, time в секундах, price, kind).

### DTO — `app/Market/DTO/`

Все — неизменяемые (`readonly`). У «выходных» есть `toArray()` для UI.

- **`Candle.php`** — OHLCV, время в **миллисекундах**. `toChartArray()` отдаёт время в
  **секундах** (как ждёт lightweight-charts). ⚠️ Не путать с `App\Models\Candle` (Eloquent).
- **`Level.php`** — уровень (цена, тип, сила 0..1, касания).
- **`Pattern.php`** — фигура (type, label, bias bullish/bearish/neutral, confidence, points,
  start/end time).
- **`TrendResult.php`** — направление + сила + наклон + adx.

### Enums — `app/Market/Enums/`

- **`LevelType.php`** — Support/Resistance + `label()` (рус.) + `color()`.
- **`TrendDirection.php`** — Up/Down/Sideways + `label()` + `color()`.

Цвета и подписи живут **в enum'ах** — отсюда фронт берёт цвета линий.

### Хранение свечей — `app/Market/Repositories/CandleRepository.php` + `app/Models/Candle.php`

- `recent()` — главный метод чтения. **Если в БД для (symbol, interval) пусто — лениво
  синкается с биржи**, чтобы дашборд имел данные при первом открытии. Сбой сети обёрнут в
  try/catch и логируется — чтение деградирует до того, что есть в БД, но не падает.
- `sync()` → тянет с биржи и `persist()`.
- `persist()` — upsert по уникальному ключу `(symbol, interval, open_time)`, чанками по 200.
- `fromStore()` — читает из БД (новые→старые лимитом, затем `reverse()`) и мапит Eloquent → DTO.

Таблица `candles` (миграция `2026_06_20_000001`): decimal-цены, `unsignedBigInteger` для
времён (ms epoch), уникальный индекс `(symbol, interval, open_time)`.

### Консольные команды — `app/Console/Commands/`

- **`candles:sync`** (`SyncCandles.php`) — живой синк с биржи. Опции `--symbol`, `--interval`;
  без них — все пары × все таймфреймы из конфига.
- **`candles:import`** (`ImportCandles.php`, `--dir=...`) — **офлайн**-импорт из JSON-файлов
  `SYMBOL__INTERVAL.json` (сырой ответ BingX) в `storage/app/seed/`. Обходной путь для среды,
  где контейнер не видит биржу (см. «Особенности dev-песочницы»).

### Планировщик — `routes/console.php`

`Schedule::command('candles:sync')->everyThirtySeconds()->withoutOverlapping(5)`. Запускается
контейнером `scheduler`. `withoutOverlapping` (redis-mutex) не даёт синкам наезжать друг на
друга; 5-минутный TTL самолечит зависший лок.

### UI — Filament страница

- **`app/Filament/Pages/MarketDashboard.php`** — серверная часть. Метод
  **`marketData($symbol, $interval)`** дёргается из браузера через `$wire.marketData(...)`
  (отдельного JSON-API роута нет). Санитизирует symbol/interval против конфига и собирает один
  payload: свечи + atr + levels + trend + patterns + ticker. `safeTicker()` оборачивает живой
  вызов — его падение не ломает страницу (в тестах деградирует в `null`).
- **`resources/views/filament/pages/market-dashboard.blade.php`** — Alpine.js-компонент
  `marketDashboard`. Тулбар (выбор пары/ТФ, тумблеры Уровни / Фигуры / ATR, бейдж тренда,
  цена, ATR), построение графика, живое обновление каждые 30с **без сброса зума** (`refresh`
  не делает `fitContent`, в отличие от `reload`). Тумблеры оверлеев пере-применяют price
  lines / line series **на клиенте** из одного закэшированного payload, без новых запросов.
  Библиотека `lightweight-charts` **самохостится** из `public/vendor/lightweight-charts/`.

### Связывание (DI) — `app/Providers/ExchangeServiceProvider.php`

Всё собирается как синглтоны. `ExchangeInterface` → `match(config('exchange.default'))`.
**Новую биржу добавляют именно сюда**: новая реализация + новая ветка `match`.

### Конфиг — `config/exchange.php`

Единственный источник правды по биржам: `default` (драйвер), `pairs` (нативный формат
`BASE-QUOTE`), `timeframes` (interval → секунды), `default_pair`, `default_timeframe`,
`klines_limit`, блок `drivers.bingx`. **Никогда не хардкодьте пары/таймфреймы в коде.**

### Тесты — `tests/`

- **`Feature/MarketDashboardTest.php`** — рендер страницы, `marketData()` отдаёт свечи+анализ,
  откат невалидных symbol/interval к дефолтам. `seedCandles()` генерит синтетический ряд.
- **`Unit/SeriesMathTest.php`** — математика.

---

## Особенности dev-песочницы (сэкономит часы)

Сеть в этой dev-среде асимметрична (это среда, не прод):

- **Контейнеры не видят интернет** (кроме Docker Hub) — нет доступа к BingX/Packagist/GitHub.
  Поэтому `composer` гоняем **на хосте**, контейнер использует bind-mounted `vendor/`. Живой
  `candles:sync` тут не работает → мост «фетч на хосте → `candles:import`».
- **Хост → порты контейнеров не работают**. HTTP проверяем изнутри сети:
  `docker compose exec web wget -qO- http://localhost/...`.
- `docker compose exec` и контейнер→БД работают нормально.
- В образе нет `intl/gd/bcmath`. **Не пишите код, зависящий от `ext-intl`** (напр. хелпер
  Laravel `Number`).

---

## Торговый модуль

### Скрипты и расписание

`candles:sync` — каждые 30 секунд (контейнер `scheduler`). Это не торговля — только обновление локального хранилища свечей.

`agent:scan` — **не запланирован**, запускается вручную. Принимает `--symbol` и `--interval`, иначе прогоняет все пары и таймфреймы из конфига.

### Что происходит при `agent:scan`

```
agent:scan
  └─ для каждой пары/таймфрейма:
       1. CandleRepository::recent()    — берёт последние свечи из БД
       2. MarketAnalyzer::atr()         — считает ATR за 14 периодов
       3. MarketAnalyzer::levels()      — находит уровни поддержки/сопротивления
       4. ближайший уровень к текущей цене
       5. PositionManager::process()    — главная логика
            ├─ ищет открытую позицию в positions table
            ├─ если позиция ОТКРЫТА → TradingAgent.evaluateExit()
            └─ если позиции НЕТ    → TradingAgent.evaluateEntry()
```

### Что проверяет TradingAgent

Индикаторы (считаются in-process из свечей): EMA 8 и EMA 21, MACD (12/26/9), ATR (SMA-метод, 14 периодов).

**Правила входа** (4 сетапа, все ATR-относительные):

| # | Правило | Суть |
|---|---------|------|
| 1 | `Bounce` | Отскок от уровня — последние 3 свечи в зоне, компрессия, импульс против уровня |
| 2 | `Retest` | Пробой + возврат — был пробойный бар в последних 10, EMA по тренду, сейчас компрессия + импульс |
| 3 | `FalseBreakout` | Ложный пробой — предыдущая свеча пробила уровень виком, тело закрылось обратно |
| 4 | `TrendPullback` | Откат в тренде — EMA-тренд на 5 барах, цена вернулась к уровню |

Глобальные фильтры входа: цена не дальше 60% ATR от уровня, последние 5 свечей шире ATR×0.30 (фильтр мёртвого флета), R:R не ниже 2.0, не дублировать сигнал из последних 5 баров.

Параметры сделки (`config/trading.php`): стоп — уровень ± ATR×0.5, Target 1 — вход ± риск×2, Target 2 — вход ± риск×4.

**Правила выхода** (приоритет сверху вниз):
1. Стоп-лосс (или break-even стоп после T1)
2. Полный выход на T2
3. Частичный (50%) на T1 + стоп в безубыток
4. Ранний выход на разворот: 2 компрессионных бара + импульс против + дивергенция / поглощение / разворот EMA8

### Исполнение ордеров

`TRADING_EXECUTOR=paper` (по умолчанию) — `PaperTradeExecutor`: никаких реальных ордеров, только запись в лог и `positions` table. `TRADING_EXECUTOR=bingx` — `BingXTradeExecutor` шлёт MARKET-ордера с bracket TP/SL на USDT-M perps через подписанные приватные эндпоинты BingX.

### Где хранятся результаты

Таблица `positions` — каждая сделка пишется с полным контекстом: индикаторы в момент входа, сигнал, уровень, ордер; при выходе — тип выхода, причина, PnL. Видно в Filament (`/admin/positions`). Граф свечей на сделку рендерится Python-скриптом `scripts/render_position.py` (опционально, `TRADING_CHART=true`).

---

## Куда вносить типичные изменения

| Задача | Где трогать |
|---|---|
| Добавить пару/таймфрейм | `config/exchange.php` (и пере-засеять) |
| Новая биржа | новая реализация `ExchangeInterface` + ветка `match` в `ExchangeServiceProvider` |
| Новый индикатор | `SeriesMath` → метод в `MarketAnalyzer`/интерфейсе → DTO → payload в `marketData()` → blade |
| Новая фигура | `PatternDetector::detect()` + приватный метод-детектор |
| Цвета/подписи уровней и тренда | enum'ы `LevelType` / `TrendDirection` |
| Торговый модуль (будущее) | потреблять `MarketAnalyzerInterface`, не лезть в биржу/БД напрямую |

---

## Запуск и проверка

```bash
make sh                                                    # шелл в контейнер app
make test                                                  # все тесты
docker compose exec app php artisan test --filter=MarketDashboardTest
make artisan c="candles:import"                            # офлайн-засев из storage/app/seed/
```

Админка: `/admin` (`admin@jtrader.local` / `password`). Дашборд: `/admin/market-dashboard`.
