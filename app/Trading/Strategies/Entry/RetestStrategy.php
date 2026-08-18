<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Entry;

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
 * Стратегия входа №2 — Пробой и ретест уровня (Retest).
 */
final class RetestStrategy implements EntryStrategyInterface
{
    /**
     * Оценка рынка на предмет пробоя и последующего ретеста уровня.
     */
    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal
    {
        // Вычисляем допустимую зону ретеста (20% от ATR)
        $zone = $ctx->atr * 0.20;
        // Проверяем расстояние от текущей цены до пробитого уровня
        if (abs($ctx->price() - $ctx->level) > $zone) {
            // Если цена ещё не вернулась на ретест уровня — сигнала нет
            return null;
        }

        // Получаем срез из последних 3 свечей
        $last3 = $ctx->slice(3);
        // Проверяем, есть ли хотя бы одна свеча компрессии (затухание волатильности на ретесте)
        $hasCompression = CandleSignals::countCompression($last3, $ctx->atr) >= 1;
        if (! $hasCompression) {
            // Если компрессии нет — сигнала нет
            return null;
        }

        // Получаем индекс текущей свечи
        $i = $ctx->i;

        // Проверяем условия для LONG (пробой вверх и ретест сверху)
        if ($this->isLongSetup($ctx, $i)) {
            // Формируем торговый план на Long с типом Retest
            return $planner->plan($ctx, SignalType::Retest, Direction::Long);
        }

        // Проверяем условия для SHORT (пробой вниз и ретест снизу)
        if ($this->isShortSetup($ctx, $i)) {
            // Формируем торговый план на Short с типом Retest
            return $planner->plan($ctx, SignalType::Retest, Direction::Short);
        }

        // Если условия не выполнены — сигнала нет
        return null;
    }

    /**
     * Проверка условий на ретест после пробоя вверх (LONG).
     */
    private function isLongSetup(RuleContext $ctx, int $i): bool
    {
        // 1. В пределах последних 10 свечей был сильный пробойный бар вверх (> 0.7 ATR выше уровня)
        return $ctx->hadBreakoutCandle(Direction::Long, 10)
            // 2. Скользящая EMA8 находится выше уровня
            && $ctx->ema8At($i) > $ctx->level
            // 3. Скользящая EMA8 выше EMA21 (восходящий тренд)
            && $ctx->ema8At($i) > $ctx->ema21At($i)
            // 4. Линия MACD положительна (выше нуля)
            && $ctx->macd['line'][$i] > 0
            // 5. Текущая свеча подтверждает отскок бычьим импульсом
            && CandleSignals::isBullishImpulse($ctx->last(), $ctx->atr);
    }

    /**
     * Проверка условий на ретест после пробоя вниз (SHORT).
     */
    private function isShortSetup(RuleContext $ctx, int $i): bool
    {
        // 1. В пределах последних 10 свечей был сильный пробойный бар вниз (> 0.7 ATR ниже уровня)
        return $ctx->hadBreakoutCandle(Direction::Short, 10)
            // 2. Скользящая EMA8 находится ниже уровня
            && $ctx->ema8At($i) < $ctx->level
            // 3. Скользящая EMA8 ниже EMA21 (нисходящий тренд)
            && $ctx->ema8At($i) < $ctx->ema21At($i)
            // 4. Линия MACD отрицательна (ниже нуля)
            && $ctx->macd['line'][$i] < 0
            // 5. Текущая свеча подтверждает отскок медвежьим импульсом
            && CandleSignals::isBearishImpulse($ctx->last(), $ctx->atr);
    }
}
