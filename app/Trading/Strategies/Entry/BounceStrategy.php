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
 * Стратегия входа №1 — Отскок от ключевого уровня (Bounce).
 */
final class BounceStrategy implements EntryStrategyInterface
{
    /**
     * Оценка рынка на предмет отскока от поддержки или сопротивления.
     */
    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal
    {
        // Вычисляем допустимую зону касания уровня (15% от ATR)
        $zone = $ctx->atr * 0.15;
        // Берём срез из последних 3 свечей
        $last3 = $ctx->slice(3);

        // Проверяем, что все 3 свечи коснулись зоны уровня
        if (! $ctx->allTouchZone($last3, $zone)) {
            // Если хотя бы одна свеча не коснулась зоны уровня — сигнала нет
            return null;
        }

        // Проверяем, что минимум 2 из 3 свечей имеют сжатый диапазон (компрессия)
        if (CandleSignals::countCompression($last3, $ctx->atr) < 2) {
            // Если компрессии недостаточно — сигнала нет
            return null;
        }

        // Получаем последнюю закрытую свечу
        $last = $ctx->last();
        // Получаем массив значений гистограммы MACD
        $hist = $ctx->macd['histogram'];
        // Получаем индекс последней свечи
        $i = $ctx->i;

        // Проверяем условия для входа в SHORT (отскок от сопротивления)
        if ($this->isShortSetup($ctx, $last, $hist, $i)) {
            // Формируем торговый план на Short с типом Bounce
            return $planner->plan($ctx, SignalType::Bounce, Direction::Short);
        }

        // Проверяем условия для входа в LONG (отскок от поддержки)
        if ($this->isLongSetup($ctx, $last, $hist, $i)) {
            // Формируем торговый план на Long с типом Bounce
            return $planner->plan($ctx, SignalType::Bounce, Direction::Long);
        }

        // Если ни одно из условий не выполнено — сигнала нет
        return null;
    }

    /**
     * Проверка условий на отскок вниз от уровня сопротивления (SHORT).
     *
     * @param array<int, float> $hist
     */
    private function isShortSetup(RuleContext $ctx, Candle $last, array $hist, int $i): bool
    {
        // 1. Текущая свеча должна быть медвежьим импульсом (тело > 0.5 ATR)
        return CandleSignals::isBearishImpulse($last, $ctx->atr)
            // 2. Скользящая EMA8 ниже уровня сопротивления или падает
            && ($ctx->ema8At($i) < $ctx->level || $ctx->ema8Falling())
            // 3. Гистограмма MACD убывает (текущее значение ниже предыдущего)
            && ($hist[$i] < ($hist[$i - 1] ?? $hist[$i]))
            // 4. Цена ещё не ушла слишком далеко вниз от уровня (не далее 0.2 ATR)
            && $ctx->price() >= $ctx->level - $ctx->atr * 0.20;
    }

    /**
     * Проверка условий на отскок вверх от уровня поддержки (LONG).
     *
     * @param array<int, float> $hist
     */
    private function isLongSetup(RuleContext $ctx, Candle $last, array $hist, int $i): bool
    {
        // 1. Текущая свеча должна быть бычьим импульсом (тело > 0.5 ATR)
        return CandleSignals::isBullishImpulse($last, $ctx->atr)
            // 2. Скользящая EMA8 выше уровня поддержки или растет
            && ($ctx->ema8At($i) > $ctx->level || $ctx->ema8Rising())
            // 3. Гистограмма MACD растет (текущее значение выше предыдущего)
            && ($hist[$i] > ($hist[$i - 1] ?? $hist[$i]))
            // 4. Цена ещё не ушла слишком далеко вверх от уровня (не далее 0.2 ATR)
            && $ctx->price() <= $ctx->level + $ctx->atr * 0.20;
    }
}
