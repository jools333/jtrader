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

## Архитектура анализа (план)

```
app/
├── Contracts/
│   ├── ExchangeInterface.php        # сырые данные биржи (пары, klines, тикер)
│   └── MarketAnalyzerInterface.php  # ATR, уровни, тренд, фигуры
├── Exchange/
│   └── BingX/                       # реализация ExchangeInterface для BingX
├── Analysis/                        # реализация MarketAnalyzerInterface
├── DTO/                             # Candle, Level, TrendResult, Pattern
└── Filament/Pages/MarketDashboard   # график + кнопки оверлеев
config/exchange.php                  # список пар, таймфреймы, активная биржа
```

Будущий торговый модуль будет потреблять `MarketAnalyzerInterface`, не зная о BingX.
