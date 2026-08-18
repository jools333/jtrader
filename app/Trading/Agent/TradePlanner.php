<?php

declare(strict_types=1);

namespace App\Trading\Agent;

// Импорт DTO сигнала на вход
use App\Trading\DTO\EntrySignal;
// Импорт перечисления направления сделки
use App\Trading\Enums\Direction;
// Импорт типа сигнала
use App\Trading\Enums\SignalType;

/**
 * Сервис планирования сделки: расчет точки входа, стоп-лосса, тейк-профитов и коэффициента R:R.
 */
final class TradePlanner
{
    /**
     * Конструктор с передачей блока конфигурации trading.php.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Построение торгового плана для сигнала.
     */
    public function plan(
        RuleContext $ctx,
        SignalType $type,
        Direction $dir,
        bool $confluence = false,
        ?float $stopPrice = null,
    ): ?EntrySignal {
        // Цена входа равна цене закрытия последней свечи
        $entry = $ctx->price();
        // Запас для стоп-лосса за уровнем в единицах ATR (по умолчанию 0.5 ATR)
        $stopBuffer = $ctx->atr * $this->cfg('stop_atr', 0.5);
        // Множитель риска для первой цели (по умолчанию 2.0R)
        $t1Mult = $this->cfg('target1_r', 2.0);
        // Множитель риска для второй цели (по умолчанию 4.0R)
        $t2Mult = $this->cfg('target2_r', 4.0);

        // Расчет для позиции LONG (покупка)
        if ($dir === Direction::Long) {
            // Базовый уровень стоп-лосса: ниже уровня поддержки на величину буфера
            $defaultStop = $ctx->level - $stopBuffer;
            // Если передан кастомный стоп (например, за тень ложного пробоя), выбираем более безопасный (наименьший)
            $stop = $stopPrice !== null ? min($defaultStop, $stopPrice) : $defaultStop;
            // Величина риска на единицу актива
            $risk = $entry - $stop;
            // Если риск отрицательный или нулевой (стоп выше входа) — отменяем сигнал
            if ($risk <= 0.0) {
                return null;
            }
            // Первая цель: Вход + Риск * 2.0
            $target1 = $entry + $risk * $t1Mult;
            // Вторая цель: Вход + Риск * 4.0
            $target2 = $entry + $risk * $t2Mult;
        } else {
            // Расчет для позиции SHORT (продажа)
            // Базовый уровень стоп-лосса: выше уровня сопротивления на величину буфера
            $defaultStop = $ctx->level + $stopBuffer;
            // Если передан кастомный стоп, выбираем наибольший (самый безопасный для шорта)
            $stop = $stopPrice !== null ? max($defaultStop, $stopPrice) : $defaultStop;
            // Величина риска на единицу актива для шорта
            $risk = $stop - $entry;
            // Если риск некорректен — отменяем сигнал
            if ($risk <= 0.0) {
                return null;
            }
            // Первая цель: Вход - Риск * 2.0
            $target1 = $entry - $risk * $t1Mult;
            // Вторая цель: Вход - Риск * 4.0
            $target2 = $entry - $risk * $t2Mult;
        }

        // Создаем и возвращаем DTO сигнала на вход с рассчитанными параметрами
        return new EntrySignal(
            type: $type,
            direction: $dir,
            entryPrice: $entry,
            stop: $stop,
            target1: $target1,
            target2: $target2,
            rrRatio: abs($target2 - $entry) / $risk,
            confluence: $confluence,
        );
    }

    /**
     * Получение числового параметра из конфига с дефолтным значением.
     */
    private function cfg(string $key, float $default): float
    {
        return (float) ($this->config[$key] ?? $default);
    }
}
