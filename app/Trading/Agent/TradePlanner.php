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
        
        // TP фиксирован на процент от цены входа (по умолчанию 0.35% чистой прибыли)
        $tpPercent = $type === SignalType::BtcLeadLag
            ? $this->cfg('lead_lag_tp_percent', 0.40) / 100.0
            : $this->cfg('tp_percent', 0.35) / 100.0;
        $tpMultiplier = $this->cfg('tp_multiplier', 2.0);
        
        // Учитываем комиссии BingX. Вход LIMIT (Maker), выход TP - MARKET (Taker)
        $makerFee = $this->cfg('fee_maker_percent', 0.02) / 100.0;
        $takerFee = $this->cfg('fee_taker_percent', 0.05) / 100.0;
        $totalFeePercent = $takerFee + $makerFee; // 0.07%
        
        // Минимальная дистанция тейка для надежного покрытия комиссий и получения прибыли
        $minTpDistance = $entry * ($tpPercent + $totalFeePercent);

        // Стоп-лосс: если передана техническая цена стопа (от BounceStrategy) — используем её
        if ($stopPrice !== null) {
            $stopDistance = abs($entry - $stopPrice);
        } elseif ($type === SignalType::BtcLeadLag && $ctx->atr > 0.0) {
            $stopDistance = min($entry * $stopPct, $ctx->atr * $this->cfg('lead_lag_stop_atr', 1.0));
        } else {
            $stopDistance = ($ctx->atr > 0.0)
                ? min($entry * $stopPct, $ctx->atr * $this->cfg('stop_atr', 1.0))
                : ($entry * $stopPct);
        }

        // Защита: дистанция стопа не должна превышать максимальный порог (2% или 2 ATR)
        $maxStopDistance = max($entry * $stopPct, $ctx->atr > 0.0 ? $ctx->atr * 2.0 : 0.0);
        if ($maxStopDistance > 0.0) {
            $stopDistance = min($stopDistance, $maxStopDistance);
        }

        // Минимальная дистанция стопа от микро-значений (0.1% от цены)
        $minStopDistance = $entry * 0.001;
        $stopDistance = max($stopDistance, $minStopDistance);

        // Дистанция тейк-профита с учетом целевого R:R (target1_r, по умолчанию 2.0R)
        $target1R = $this->cfg('target1_r', 2.0);
        $tpDistance = max($minTpDistance, $stopDistance * $target1R);

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

        // Рассчитываем фактический R:R для Target 1
        $rrRatio = $stopDistance > 0.0 ? round($tpDistance / $stopDistance, 2) : $target1R;

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

