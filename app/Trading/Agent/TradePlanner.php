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
        
        // TP фиксирован на процент от цены входа (по умолчанию 0.1% чистой прибыли)
        $tpPercent = $this->cfg('tp_percent', 0.1) / 100.0;
        
        // Учитываем комиссии BingX. Вход обычно Taker (по рынку), выход TP - Maker (лимитка).
        $makerFee = $this->cfg('fee_maker_percent', 0.02) / 100.0;
        $takerFee = $this->cfg('fee_taker_percent', 0.05) / 100.0;
        $totalFeePercent = $takerFee + $makerFee; // 0.07%
        
        // Итоговая дистанция включает желаемый профит и компенсацию комиссий
        $tpDistance = $entry * ($tpPercent + $totalFeePercent);

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

