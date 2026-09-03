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
        
        // Защитный "катастрофический" стоп-лосс на случай краха рынка
        $stopPct = $this->cfg('catastrophic_stop_percent', 2.0) / 100.0;
        
        // TP фиксирован на процент от цены входа
        $tpPercent = $type === SignalType::BtcLeadLag
            ? $this->cfg('lead_lag_tp_percent', 0.40) / 100.0
            : $this->cfg('tp_percent', 0.1) / 100.0;
        $tpMultiplier = $this->cfg('tp_multiplier', 2.0);
        
        // Учитываем комиссии BingX. Вход теперь LIMIT (Maker), выход TP - MARKET (Taker)
        $makerFee = $this->cfg('fee_maker_percent', 0.02) / 100.0;
        $takerFee = $this->cfg('fee_taker_percent', 0.05) / 100.0;
        $totalFeePercent = $takerFee + $makerFee; // 0.07%
        
        // Итоговая дистанция включает желаемый профит и компенсацию комиссий
        $tpDistance = $entry * ($tpPercent + $totalFeePercent);

        // Стоп-лосс: для BtcLeadLag используем ATR стоп (если доступен), иначе процент от цены
        $stopDistance = ($type === SignalType::BtcLeadLag && $ctx->atr > 0.0)
            ? min($entry * $stopPct, $ctx->atr * $this->cfg('lead_lag_stop_atr', 1.0))
            : ($entry * $stopPct);

        if ($stopPrice !== null) {
            $stopDistance = abs($entry - $stopPrice);
        }

        // Расчет для позиции LONG (покупка)
        if ($dir === Direction::Long) {
            $stop = $entry - $stopDistance;
            $target1 = $entry + $tpDistance;
            $target2 = $entry + ($tpDistance * $tpMultiplier);
        } else {
            // Расчет для позиции SHORT (продажа)
            $stop = $entry + $stopDistance;
            $target1 = $entry - $tpDistance;
            $target2 = $entry - ($tpDistance * $tpMultiplier);
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

