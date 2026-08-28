<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Exit;

// Импорт контекста правил
use App\Trading\Agent\RuleContext;
// Импорт интерфейса стратегии выхода
use App\Trading\Contracts\ExitStrategyInterface;
// Импорт DTO сигнала на выход
use App\Trading\DTO\ExitSignal;
// Импорт DTO состояния открытой позиции
use App\Trading\DTO\PositionState;
// Импорт перечисления направления
use App\Trading\Enums\Direction;
// Импорт перечисления типа выхода
use App\Trading\Enums\ExitType;

/**
 * Стратегия выхода №4 (Приоритет 1) — Защитный Стоп-Лосс (Stop-Loss).
 */
final class StopLossStrategy implements ExitStrategyInterface
{
    /**
     * Оценка срабатывания защитного стопа или стопа в безубытке.
     */
    public function evaluate(RuleContext $ctx, PositionState $position): ?ExitSignal
    {
        // Текущая цена инструмента
        $price = $ctx->price();
        // Флаг: позиция является лонговой
        $isLong = $position->direction === Direction::Long;
        // Если уже был взят Target 1, используем цену входа как безубыточный стоп, иначе исходный стоп
        $stop = $position->breakevenSet ? $position->entryPrice : $position->stopPrice;

        if ($stop <= 0.0) {
            return null;
        }

        // Проверяем факт пробития уровня стоп-лосса ценой
        $triggered = $isLong ? ($price <= $stop) : ($price >= $stop);

        // Если стоп пробит
        if ($triggered) {
            // Формируем сигнал на закрытие 100% позиции по стоп-лоссу
            return new ExitSignal(ExitType::StopLoss, 100);
        }

        // Стоп не сработал
        return null;
    }
}
