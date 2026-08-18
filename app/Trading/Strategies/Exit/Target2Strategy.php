<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Exit;

// Импорт контекста правил
use App\Trading\Agent\RuleContext;
// Импорт интерфейса стратегии выхода
use App\Trading\Contracts\ExitStrategyInterface;
// Импорт DTO сигнала на выход
use App\Trading\DTO\ExitSignal;
// Импорт DTO состояния позиции
use App\Trading\DTO\PositionState;
// Импорт перечисления направления
use App\Trading\Enums\Direction;
// Импорт перечисления типа выхода
use App\Trading\Enums\ExitType;

/**
 * Стратегия выхода №2 (Приоритет 2) — Полная фиксация прибыли по Target 2 (4R).
 */
final class Target2Strategy implements ExitStrategyInterface
{
    /**
     * Оценка достижения дальней цели (Target 2).
     */
    public function evaluate(RuleContext $ctx, PositionState $position): ?ExitSignal
    {
        // Текущая цена
        $price = $ctx->price();
        // Флаг лонговой позиции
        $isLong = $position->direction === Direction::Long;

        // Проверяем, коснулась ли цена уровня Target 2
        $triggered = $isLong
            ? ($price >= $position->target2)
            : ($price <= $position->target2);

        // Если цель достигнута
        if ($triggered) {
            // Формируем сигнал на закрытие оставшихся 100% позиции по цели 2
            return new ExitSignal(ExitType::Target2, 100);
        }

        // Цель не достигнута
        return null;
    }
}
