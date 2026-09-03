<?php

declare(strict_types=1);

namespace App\Trading\Services;

use App\Market\Contracts\MarketAnalyzerInterface;
use App\Market\DTO\Candle;
use App\Market\DTO\Level;
use App\Market\Repositories\CandleRepository;
use App\Trading\Execution\PositionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Детектор импульсов BTC для стратегии BTC Lead-Lag.
 *
 * Отслеживает тики цены BTC в реальном времени через WebSocket. При обнаружении
 * резкого движения (памп или дамп) запускает быстрый скан пар, находит наиболее
 * подходящую отстающую монету и инициирует вход в позицию через PositionManager.
 */
final class BtcImpulseDetector
{
    /** Ключ кэша для соблюдения кулдауна между импульсными сделками */
    public const COOLDOWN_KEY = 'btc_lead_lag_cooldown';

    /** Буфер цен тиков BTC: [unix_timestamp => price] */
    private array $priceBuffer = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly CandleRepository $candlesRepo,
        private readonly MarketAnalyzerInterface $analyzer,
        private readonly PositionManager $manager,
        private readonly array $config = [],
    ) {
    }

    /**
     * Обработка тика свечи BTC из WebSocket.
     */
    public function onBtcTick(Candle $candle): void
    {
        $enabled = (bool) ($this->config['lead_lag_enabled'] ?? true);
        if (! $enabled) {
            return;
        }

        $now = time();
        $this->priceBuffer[$now] = $candle->close;

        // Храним в буфере только последние 180 секунд
        foreach ($this->priceBuffer as $ts => $price) {
            if ($now - $ts > 180) {
                unset($this->priceBuffer[$ts]);
            }
        }

        // 1. Изменение цены внутри текущей свечи 1m: (close - open) / open * 100%
        $candleMovePct = $candle->open > 0.0 ? (($candle->close - $candle->open) / $candle->open) * 100.0 : 0.0;

        // 2. Скользящее изменение цены в буфере за последние 60-120 секунд
        $oldestPrice = reset($this->priceBuffer);
        $rollingMovePct = ($oldestPrice > 0.0) ? (($candle->close - $oldestPrice) / $oldestPrice) * 100.0 : 0.0;

        $impulseThreshold = (float) ($this->config['lead_lag_btc_impulse_pct'] ?? 0.40);

        // Выбираем наибольший зафиксированный импульс
        $btcMovePct = abs($candleMovePct) >= abs($rollingMovePct) ? $candleMovePct : $rollingMovePct;

        if (abs($btcMovePct) < $impulseThreshold) {
            return;
        }

        // Проверяем кулдаун импульсов
        if (Cache::has(self::COOLDOWN_KEY)) {
            return;
        }

        Log::info(sprintf(
            '⚡ [BTC Lead-Lag] Зафиксирован импульс BTC: %.2f%% (порог: %.2f%%). Сканирование альткоинов...',
            $btcMovePct,
            $impulseThreshold
        ));

        $this->scanAndTrade($btcMovePct);
    }

    /**
     * Сканирование альткоинов и вход в позицию по лучшей отстающей монете.
     */
    public function scanAndTrade(float $btcMovePct): void
    {
        $symbols = (array) config('exchange.pairs');
        $excluded = array_merge(['BTC-USDT'], (array) config('trading.excluded_symbols', []));
        $tradingInterval = (string) ($this->config['lead_lag_interval'] ?? '5m');
        $levelInterval = $this->higherTimeframe($tradingInterval);
        $cooldownMinutes = (int) ($this->config['lead_lag_cooldown_minutes'] ?? 5);

        // Загружаем свечи BTC для окна оценки агента
        $btcCandles = $this->candlesRepo->recent('BTC-USDT', $tradingInterval);
        if (count($btcCandles) < 10) {
            return;
        }

        foreach ($symbols as $symbol) {
            if (in_array($symbol, $excluded, true)) {
                continue;
            }

            // Пропускаем, если по монете уже есть открытая позиция
            if ($this->manager->openPosition($symbol, $tradingInterval) !== null) {
                continue;
            }

            // Пропускаем, если по монете действует кулдаун после закрытия
            if ($this->manager->isCoolingDown($symbol)) {
                continue;
            }

            try {
                $candles = $this->candlesRepo->recent($symbol, $tradingInterval);
                if (count($candles) < 50) {
                    continue;
                }

                $atr = $this->analyzer->atr($symbol, $tradingInterval);
                $levels = $this->analyzer->levels($symbol, $levelInterval);
                $level = $this->nearestLevel($levels, $candles) ?? end($candles)->close;

                // Запускаем обработку агента и исполнение ордера
                $result = $this->manager->process($symbol, $tradingInterval, $candles, $level, $atr, $btcCandles);

                if ($result->entrySignal !== null) {
                    Log::info(sprintf(
                        '🚀 [BTC Lead-Lag] Открыта сделка по %s %s (%s) по цене %.4f за импульсом BTC (%.2f%%)',
                        $symbol,
                        $tradingInterval,
                        $result->entrySignal->direction->value,
                        $result->entrySignal->entryPrice,
                        $btcMovePct
                    ));

                    // Активируем кулдаун импульсов
                    Cache::put(self::COOLDOWN_KEY, true, now()->addMinutes($cooldownMinutes));

                    // Открываем максимум 1 сделку на один импульс BTC
                    break;
                }
            } catch (Throwable $e) {
                Log::warning(sprintf('⚠️ [BTC Lead-Lag] Ошибка сканирования %s: %s', $symbol, $e->getMessage()));
            }
        }
    }

    private function higherTimeframe(string $interval): string
    {
        $timeframes = array_keys((array) config('exchange.timeframes'));
        $idx = array_search($interval, $timeframes, true);
        if ($idx === false || $idx >= count($timeframes) - 1) {
            return $interval;
        }

        return $timeframes[$idx + 1];
    }

    /**
     * @param array<int, Level> $levels
     * @param array<int, Candle> $candles
     */
    private function nearestLevel(array $levels, array $candles): ?float
    {
        if ($levels === []) {
            return null;
        }

        $price = $candles[count($candles) - 1]->close;
        usort($levels, static fn (Level $a, Level $b) => abs($a->price - $price) <=> abs($b->price - $price));

        return $levels[0]->price;
    }
}
