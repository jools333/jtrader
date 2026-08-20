<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Entry;

// Импорт DTO свечи
use App\Market\DTO\Candle;
// Импорт контекста правил с индикаторами и свечами
use App\Trading\Agent\RuleContext;
// Импорт сервиса построения торгового плана
use App\Trading\Agent\TradePlanner;
// Импорт утилит для свечного анализа
use App\Trading\Analysis\CandleSignals;
// Импорт интерфейса стратегии входа
use App\Trading\Contracts\EntryStrategyInterface;
// Импорт DTO сигнала на вход
use App\Trading\DTO\EntrySignal;
// Импорт перечисления направления сделки (Long/Short)
use App\Trading\Enums\Direction;
// Импорт перечисления типов сигналов
use App\Trading\Enums\SignalType;

/**
 * Стратегия входа №1 — Отскок от ключевого уровня (Bounce / Пробой и откат).
 *
 * Алгоритм анализирует глубокую историю свечей (20-30 баров) по Price Action:
 * 1. Предшествующее движение / импульс от уровня (выход цены за пределы уровня);
 * 2. Коррекционный откат (pullback) обратно к уровню;
 * 3. Тест зоны уровня с удержанием и затуханием волатильности (компрессия);
 * 4. Импульсный отбой от уровня на текущей свече (триггер входа).
 */
final class BounceStrategy implements EntryStrategyInterface
{
    /** Глубина анализируемого окна свечей */
    private const int LOOKBACK = 25;

    /**
     * Оценка рынка на предмет отскока от поддержки или сопротивления.
     */
    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal
    {
        // Проверяем, что в истории достаточно свечей для глубокого анализа
        if ($ctx->n < 15 || $ctx->atr <= 0.0) {
            return null;
        }

        // Берем срез свечей для анализа паттерна (до 25 свечей)
        $lookback = min($ctx->n, self::LOOKBACK);
        $window = $ctx->slice($lookback);
        $m = count($window);

        // Последняя закрытая свеча (триггерный бар)
        $last = $window[$m - 1];

        // Проверяем условия для входа в LONG (отскок от поддержки вверх)
        $longSignal = $this->checkLongSetup($ctx, $planner, $window, $last);
        if ($longSignal !== null) {
            return $longSignal;
        }

        // Проверяем условия для входа в SHORT (отскок от сопротивления вниз)
        $shortSignal = $this->checkShortSetup($ctx, $planner, $window, $last);
        if ($shortSignal !== null) {
            return $shortSignal;
        }

        // Сигнал не сформирован
        return null;
    }

    /**
     * Анализ паттерна на покупку (LONG): Пробой/движение вверх -> Откат к поддержке -> Сжатие -> Бычий отбой.
     *
     * @param array<int, Candle> $window
     */
    private function checkLongSetup(
        RuleContext $ctx,
        TradePlanner $planner,
        array $window,
        Candle $last,
    ): ?EntrySignal {
        $m = count($window);
        $atr = $ctx->atr;
        $level = $ctx->level;

        // 1. Триггерная свеча: должна быть бычьим импульсом, закрывающимся выше уровня
        $isBullishImpulse = $last->close > $last->open
            && CandleSignals::body($last) >= $atr * 0.35
            && $last->close > $level;

        if (! $isBullishImpulse) {
            return null;
        }

        // 2. Зона входа: цена не должна уйти дальше 50% ATR от уровня
        if ($last->close > $level + $atr * 0.50) {
            return null;
        }

        // 3. Поиск предшествующего пика (High) выше уровня в окне истории
        // Исключаем последние 2 бара перед триггером, чтобы было место для отката
        $searchEnd = $m - 3;
        if ($searchEnd < 1) {
            return null;
        }

        $peakHigh = -INF;
        $peakIdx = -1;

        for ($i = 0; $i <= $searchEnd; $i++) {
            if ($window[$i]->high > $peakHigh) {
                $peakHigh = $window[$i]->high;
                $peakIdx = $i;
            }
        }

        // Цена должна была находиться/выйти выше уровня минимум на 0.35 ATR
        if ($peakHigh < $level + $atr * 0.35 || $peakIdx < 0) {
            return null;
        }

        // 4. Анализ фазы отката (свечи между пиком и триггерной свечой)
        $pullbackCount = ($m - 1) - ($peakIdx + 1);
        if ($pullbackCount < 1) {
            return null;
        }

        $pullbackCandles = array_slice($window, $peakIdx + 1, $pullbackCount);

        // Находим минимум отката
        $lows = array_map(static fn (Candle $c) => $c->low, $pullbackCandles);
        $minLow = min($lows);

        // Проверяем тест зоны уровня: цена на откате должна была коснуться поддержки
        if ($minLow > $level + $atr * 0.25) {
            return null;
        }

        // Проверяем удержание уровня: цена не должна провалиться глубоко под поддержку
        if ($minLow < $level - $atr * 0.40) {
            return null;
        }

        // 5. Проверяем компрессию / затухание волатильности на откате
        $hasCompression = CandleSignals::countCompression($pullbackCandles, $atr) >= 1
            || $this->hasNarrowRangeCandle($pullbackCandles, $atr);

        if (! $hasCompression) {
            return null;
        }

        // 6. Расчет стоп-лосса за локальный минимум отката
        $stopPrice = $minLow - $atr * 0.10;

        return $planner->plan($ctx, SignalType::Bounce, Direction::Long, false, $stopPrice);
    }

    /**
     * Анализ паттерна на продажу (SHORT): Движение вниз -> Откат к сопротивлению -> Сжатие -> Медвежий отбой.
     *
     * @param array<int, Candle> $window
     */
    private function checkShortSetup(
        RuleContext $ctx,
        TradePlanner $planner,
        array $window,
        Candle $last,
    ): ?EntrySignal {
        $m = count($window);
        $atr = $ctx->atr;
        $level = $ctx->level;

        // 1. Триггерная свеча: должна быть медвежьим импульсом, закрывающимся ниже уровня
        $isBearishImpulse = $last->close < $last->open
            && CandleSignals::body($last) >= $atr * 0.35
            && $last->close < $level;

        if (! $isBearishImpulse) {
            return null;
        }

        // 2. Зона входа: цена не должна уйти дальше 50% ATR от уровня
        if ($last->close < $level - $atr * 0.50) {
            return null;
        }

        // 3. Поиск предшествующего минимума (Low) ниже уровня в окне истории
        $searchEnd = $m - 3;
        if ($searchEnd < 1) {
            return null;
        }

        $troughLow = INF;
        $troughIdx = -1;

        for ($i = 0; $i <= $searchEnd; $i++) {
            if ($window[$i]->low < $troughLow) {
                $troughLow = $window[$i]->low;
                $troughIdx = $i;
            }
        }

        // Цена должна была находиться/выйти ниже уровня минимум на 0.35 ATR
        if ($troughLow > $level - $atr * 0.35 || $troughIdx < 0) {
            return null;
        }

        // 4. Анализ фазы отката (свечи между минимумом и триггерной свечой)
        $pullbackCount = ($m - 1) - ($troughIdx + 1);
        if ($pullbackCount < 1) {
            return null;
        }

        $pullbackCandles = array_slice($window, $troughIdx + 1, $pullbackCount);

        // Находим максимум отката
        $highs = array_map(static fn (Candle $c) => $c->high, $pullbackCandles);
        $maxHigh = max($highs);

        // Проверяем тест зоны уровня: цена на откате должна была коснуться сопротивления
        if ($maxHigh < $level - $atr * 0.25) {
            return null;
        }

        // Проверяем удержание уровня: цена не должна улететь выше сопротивления
        if ($maxHigh > $level + $atr * 0.40) {
            return null;
        }

        // 5. Проверяем компрессию / затухание волатильности на откате
        $hasCompression = CandleSignals::countCompression($pullbackCandles, $atr) >= 1
            || $this->hasNarrowRangeCandle($pullbackCandles, $atr);

        if (! $hasCompression) {
            return null;
        }

        // 6. Расчет стоп-лосса за локальный максимум отката
        $stopPrice = $maxHigh + $atr * 0.10;

        return $planner->plan($ctx, SignalType::Bounce, Direction::Short, false, $stopPrice);
    }

    /**
     * Дополнительная проверка на свечи с узким телом/диапазоном в фазе отката.
     *
     * @param array<int, Candle> $candles
     */
    private function hasNarrowRangeCandle(array $candles, float $atr): bool
    {
        foreach ($candles as $c) {
            if (CandleSignals::body($c) <= $atr * 0.35 && $c->range() <= $atr * 0.70) {
                return true;
            }
        }

        return false;
    }
}
