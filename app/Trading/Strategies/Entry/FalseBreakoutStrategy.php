<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Entry;

// Импорт контекста правил
use App\Trading\Agent\RuleContext;
// Импорт сервиса планирования сделок
use App\Trading\Agent\TradePlanner;
// Импорт сигналов свечного анализа
use App\Trading\Analysis\CandleSignals;
// Импорт интерфейса стратегии входа
use App\Trading\Contracts\EntryStrategyInterface;
// Импорт DTO сигнала на вход
use App\Trading\DTO\EntrySignal;
// Импорт направления сделки
use App\Trading\Enums\Direction;
// Импорт типа сигнала
use App\Trading\Enums\SignalType;

/**
 * Стратегия входа №3 — Ложный пробой уровня (False Breakout).
 */
final class FalseBreakoutStrategy implements EntryStrategyInterface
{
    /**
     * Оценка рынка на предмет ложного прокола уровня тенью и возврата цены обратно.
     */
    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal
    {
        // Проверяем, что в истории есть хотя бы 2 свечи
        if ($ctx->n < 2) {
            return null;
        }

        // Предпоследняя свеча (на которой произошел прокол)
        $penult = $ctx->candles[$ctx->i - 1];
        // Последняя закрытая свеча (которая подтверждает возврат)
        $last = $ctx->last();
        // Текущее значение волатильности ATR
        $atr = $ctx->atr;
        // Индекс текущей свечи
        $i = $ctx->i;
        // Гистограмма индикатора MACD
        $hist = $ctx->macd['histogram'];

        // --- Сценарий SHORT: ложный пробой сопротивления вверх ---
        // 1. High предпоследней свечи выше уровня + 10% ATR (был прокол)
        if ($penult->high > $ctx->level + $atr * 0.10
            // 2. Тело закрылось обратно под уровень
            && $penult->close < $ctx->level
            // 3. Глубина прокола тени составила не менее 15% ATR
            && ($penult->high - $ctx->level) > $atr * 0.15
            // 4. Текущая свеча подтверждает разворот медвежьим импульсом (тело > 0.4 ATR)
            && CandleSignals::isBearishImpulse($last, $atr, 0.4)
            // 5. EMA8 ниже уровня или разворачивается вниз
            && ($ctx->ema8At($i) < $ctx->level || $ctx->ema8Falling())
            // 6. Гистограмма MACD отрицательна или снижается
            && ($hist[$i] < 0 || $hist[$i] < ($hist[$i - 1] ?? $hist[$i]))
        ) {
            // Стоп ставится за вершину тени ложного пробоя (High + 0.10 ATR)
            $stopPrice = $penult->high + $atr * 0.10;
            // Формируем торговый план на Short
            return $planner->plan($ctx, SignalType::FalseBreakout, Direction::Short, false, $stopPrice);
        }

        // --- Сценарий LONG: ложный пробой поддержки вниз ---
        // 1. Low предпоследней свечи ниже уровня - 10% ATR (был прокол вниз)
        if ($penult->low < $ctx->level - $atr * 0.10
            // 2. Тело закрылось обратно над уровнем поддержки
            && $penult->close > $ctx->level
            // 3. Глубина прокола тени составила не менее 15% ATR
            && ($ctx->level - $penult->low) > $atr * 0.15
            // 4. Текущая свеча подтверждает разворот бычьим импульсом (тело > 0.4 ATR)
            && CandleSignals::isBullishImpulse($last, $atr, 0.4)
            // 5. EMA8 выше уровня или разворачивается вверх
            && ($ctx->ema8At($i) > $ctx->level || $ctx->ema8Rising())
            // 6. Гистограмма MACD положительна или растет
            && ($hist[$i] > 0 || $hist[$i] > ($hist[$i - 1] ?? $hist[$i]))
        ) {
            // Стоп ставится за минимум тени ложного пробоя (Low - 0.10 ATR)
            $stopPrice = $penult->low - $atr * 0.10;
            // Формируем торговый план на Long
            return $planner->plan($ctx, SignalType::FalseBreakout, Direction::Long, false, $stopPrice);
        }

        // Если условий нет — возвращаем null
        return null;
    }
}
