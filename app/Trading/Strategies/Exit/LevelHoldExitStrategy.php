<?php

declare(strict_types=1);

namespace App\Trading\Strategies\Exit;

use App\Trading\Agent\RuleContext;
use App\Trading\Contracts\ExitStrategyInterface;
use App\Trading\DTO\ExitSignal;
use App\Trading\DTO\PositionState;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitType;
use App\Trading\Enums\SignalType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Стратегия выхода №1 для сетапов «пробой + ретест» — полная фиксация цели и
 * тайм-стоп.
 *
 * Отличие от старых правил: цель 2R закрывается ПОЛНОСТЬЮ (без частичной
 * фиксации 50% и переноса стопа), а если сетап не отработал за отведённое
 * время — позиция закрывается по рынку, чтобы не платить комиссии за
 * «висящий» шум. Именно удержание до цели и отказ от микроприбылей дали
 * положительное ожидание в бэктесте.
 */
final class LevelHoldExitStrategy implements ExitStrategyInterface
{
    public function __construct(
        /** Максимальное время удержания позиции в часах (0 = без тайм-стопа). */
        private readonly int $maxHoldHours = 16,
        /** Тайм-стоп применяется только к этим типам сигналов (пусто = ко всем). */
        private readonly array $timeStopSignalTypes = [SignalType::LevelRetest->value],
    ) {}

    public function evaluate(RuleContext $ctx, PositionState $position): ?ExitSignal
    {
        // 1. Полная фиксация по цели.
        if ($position->target1 > 0.0) {
            $price = $ctx->price();
            $hit = $position->direction === Direction::Long
                ? $price >= $position->target1
                : $price <= $position->target1;

            if ($hit && ! $position->breakevenSet) {
                return new ExitSignal(ExitType::Target1, 100);
            }
        }

        // 2. Тайм-стоп: сетап не отработал за отведённое окно.
        if ($this->maxHoldHours > 0
            && $position->openedAt instanceof CarbonInterface
            && $this->appliesTimeStop($position)
            && $position->openedAt->diffInHours(Carbon::now()) >= $this->maxHoldHours
        ) {
            return new ExitSignal(ExitType::TimeStop, 100);
        }

        return null;
    }

    private function appliesTimeStop(PositionState $position): bool
    {
        if ($this->timeStopSignalTypes === []) {
            return true;
        }

        // Позиции без известного типа сигнала (импорт с биржи) тайм-стопом не
        // закрываем, чтобы не рубить чужие/старые сделки.
        return $position->signalType !== null
            && in_array($position->signalType, $this->timeStopSignalTypes, true);
    }
}
