<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Exit;

// Импорт контекста правил
use App\Trading\Agent\RuleContext;
// Импорт интерфейса стратегии выхода
use App\Trading\Contracts\ExitStrategyInterface;
// Импорт DTO сигнала выхода
use App\Trading\DTO\ExitSignal;
// Импорт состояния позиции
use App\Trading\DTO\PositionState;
// Импорт перечисления направления
use App\Trading\Enums\Direction;
// Импорт типа выхода
use App\Trading\Enums\ExitType;

/**
 * Стратегия выхода №1 (Приоритет 3) — Частичная фиксация (50%) на Target 1 (2R) и перенос стопа в безубыток.
 */
final class Target1Strategy implements ExitStrategyInterface
{
    /**
     * Оценка достижения первой цели прибыли (Target 1).
     */
    public function evaluate(RuleContext $ctx, PositionState $position): ?ExitSignal
    {
        // Если безубыток уже установлен (Target 1 уже брали ранее), повторно не срабатываем
        if ($position->breakevenSet) {
            return null;
        }

        // Текущая цена
        $price = $ctx->price();
        // Флаг направления
        $isLong = $position->direction === Direction::Long;

        // Проверяем, коснулась ли цена уровня Target 1
        $triggered = $isLong
            ? ($price >= $position->target1)
            : ($price <= $position->target1);

        // Если цель достигнута
        if ($triggered) {
            // Формируем сигнал на закрытие 50% объема и перенос стопа на цену входа (entryPrice)
            return new ExitSignal(ExitType::Target1, 50, moveStopTo: $position->entryPrice);
        }

        // Цель не достигнута
        return null;
    }
}
