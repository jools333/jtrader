<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Entry;

// Импорт контекста правил
use App\Trading\Agent\RuleContext;
// Импорт сервиса планирования сделок
use App\Trading\Agent\TradePlanner;
// Импорт методов свечного анализа
use App\Trading\Analysis\CandleSignals;
// Импорт интерфейса стратегии входа
use App\Trading\Contracts\EntryStrategyInterface;
// Импорт DTO сигнала на вход
use App\Trading\DTO\EntrySignal;
// Импорт перечисления направления сделки
use App\Trading\Enums\Direction;
// Импорт типа сигнала
use App\Trading\Enums\SignalType;

/**
 * Стратегия входа №4 — Откат по тренду к ключевому уровню (Trend Pullback).
 */
final class TrendPullbackStrategy implements EntryStrategyInterface
{
    /**
     * Оценка рынка на предмет отката в сторону доминирующего тренда.
     */
    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal
    {
        // Допустимая зона отката (15% от ATR)
        $zone = $ctx->atr * 0.15;
        // Проверяем, находится ли цена близко к уровню
        if (abs($ctx->price() - $ctx->level) > $zone) {
            return null; // цена не дошла или проскочила уровень
        }

        // Проверяем, что цена не растратила более 20% хода ATR от уровня
        if (abs($ctx->atrTravelSigned()) >= 0.20) {
            return null;
        }

        // Берём срез из последних 3 свечей
        $last3 = $ctx->slice(3);
        // Проверяем наличие хотя бы одной свечи компрессии (затухание отката)
        if (CandleSignals::countCompression($last3, $ctx->atr) < 1) {
            return null;
        }

        // Индекс текущей свечи
        $i = $ctx->i;
        // Проверяем конфлюэнцию: находится ли скользящая EMA21 близко к уровню (< 20% ATR)
        $confluence = abs($ctx->ema21At($i) - $ctx->level) < $ctx->atr * 0.20;

        // LONG — при восходящем тренде и откате к поддержке
        if ($this->isLongSetup($ctx)) {
            // Формируем торговый план на покупку с отметкой о конфлюэнции
            return $planner->plan($ctx, SignalType::TrendPullback, Direction::Long, $confluence);
        }

        // SHORT — при нисходящем тренде и откате к сопротивлению
        if ($this->isShortSetup($ctx)) {
            // Формируем торговый план на продажу с отметкой о конфлюэнции
            return $planner->plan($ctx, SignalType::TrendPullback, Direction::Short, $confluence);
        }

        // Сигналов нет
        return null;
    }

    /**
     * Проверка условий на покупку по восходящему тренду.
     */
    private function isLongSetup(RuleContext $ctx): bool
    {
        // 1. EMA8 была выше EMA21 на протяжении всех последних 5 свечей (устойчивый аптренд)
        return $ctx->emaTrend(Direction::Long, 5)
            // 2. Текущая свеча подтверждает окончание отката бычьим импульсом
            && CandleSignals::isBullishImpulse($ctx->last(), $ctx->atr)
            // 3. Нет медвежьей дивергенции по MACD (тренд не ослаб)
            && ! $ctx->bearishDivergence();
    }

    /**
     * Проверка условий на продажу по нисходящему тренду.
     */
    private function isShortSetup(RuleContext $ctx): bool
    {
        // 1. EMA8 была ниже EMA21 на протяжении всех последних 5 свечей (устойчивый даунтренд)
        return $ctx->emaTrend(Direction::Short, 5)
            // 2. Текущая свеча подтверждает окончание отката медвежьим импульсом
            && CandleSignals::isBearishImpulse($ctx->last(), $ctx->atr)
            // 3. Нет бычьей дивергенции по MACD (тренд не ослаб)
            && ! $ctx->bullishDivergence();
    }
}
