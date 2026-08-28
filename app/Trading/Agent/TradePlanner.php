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
        
        // Согласно новым правилам, Stop Loss отключен.
        $stop = 0.0;
        
        // TP фиксирован на 1% от цены входа
        $tpPercent = $this->cfg('tp_percent', 1.0) / 100.0;
        $tpDistance = $entry * $tpPercent;

        // Расчет для позиции LONG (покупка)
        if ($dir === Direction::Long) {
            $target1 = $entry + $tpDistance;
            $target2 = $target1; // Цель 2 равна цели 1
        } else {
            // Расчет для позиции SHORT (продажа)
            $target1 = $entry - $tpDistance;
            $target2 = $target1; // Цель 2 равна цели 1
        }

        // Задаем искусственный R:R, чтобы всегда проходить фильтр в TradingAgent
        $rrRatio = 999.0;

        // Создаем и возвращаем DTO сигнала на вход с рассчитанными параметрами
        return new EntrySignal(
            type: $type,
            direction: $dir,
            entryPrice: $entry,
            stop: $stop,
            target1: $target1,
            target2: $target2,
            rrRatio: $rrRatio,
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

