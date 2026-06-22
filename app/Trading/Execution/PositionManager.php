<?php

declare(strict_types=1);

namespace App\Trading\Execution;

use App\Models\Position;
use App\Trading\Charting\ChartRenderer;
use App\Trading\Contracts\TradeExecutorInterface;
use App\Trading\Contracts\TradingAgentInterface;
use App\Trading\DTO\AgentResult;
use App\Trading\DTO\EntrySignal;
use App\Trading\DTO\ExitSignal;
use App\Trading\DTO\IndicatorSnapshot;
use App\Trading\DTO\PositionState;
use App\Trading\Enums\ExitType;
use Illuminate\Support\Carbon;

/**
 * Drives one agent evaluation for a (symbol, interval) and acts on the result:
 * routes orders through a {@see TradeExecutorInterface} and records every entry
 * and exit — with the triggering indicators/parameters — in the `positions`
 * table for audit.
 *
 * Only one open position per (symbol, interval) is managed at a time: while one
 * is open the agent evaluates exits; when flat it evaluates entries.
 */
final class PositionManager
{
    /** @param array<string, mixed> $config the `config/trading.php` block */
    public function __construct(
        private readonly TradingAgentInterface $agent,
        private readonly TradeExecutorInterface $executor,
        private readonly array $config = [],
        private readonly ?ChartRenderer $chart = null,
    ) {
    }

    /**
     * Evaluate and act for one bar. Returns the agent result so callers can log
     * or display it.
     *
     * @param array<int, \App\Market\DTO\Candle> $candles oldest -> newest
     */
    public function process(string $symbol, string $interval, array $candles, float $level, ?float $atr = null): AgentResult
    {
        $open = $this->openPosition($symbol, $interval);

        $state = $open !== null ? $this->toState($open) : null;
        $recent = $open !== null ? [] : $this->recentSignalTypes($symbol, $interval);

        $result = $this->agent->evaluate($candles, $level, $atr, $state, $recent);

        if ($open !== null && $result->exitSignal !== null) {
            $position = $this->applyExit($open, $result->exitSignal, $this->currentPrice($candles));
            $this->attachChart($position, $candles);
        } elseif ($open === null && $result->entrySignal !== null) {
            $entryOpenTime = $this->currentOpenTime($candles);
            $position = $this->openFromSignal($symbol, $interval, $result->entrySignal, $result->indicators, $level, $entryOpenTime);
            $this->attachChart($position, $candles);
        }

        return $result;
    }

    /**
     * Best-effort: render the position chart and store its path. Never lets a
     * charting failure interrupt trade management.
     *
     * @param array<int, \App\Market\DTO\Candle> $candles
     */
    private function attachChart(Position $position, array $candles): void
    {
        if ($this->chart === null) {
            return;
        }

        $path = $this->chart->render($position, $candles);
        if ($path !== null) {
            $position->update(['chart_path' => $path]);
        }
    }

    /** The currently open position for the pair/timeframe, if any. */
    public function openPosition(string $symbol, string $interval): ?Position
    {
        return Position::query()
            ->open()
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->latest('opened_at')
            ->first();
    }

    /**
     * Open a position from an entry signal: route the order and log the record
     * with the indicator snapshot and signal parameters that triggered it.
     */
    public function openFromSignal(
        string $symbol,
        string $interval,
        EntrySignal $signal,
        IndicatorSnapshot $indicators,
        ?float $level = null,
        ?int $entryOpenTime = null,
    ): Position {
        $quantity = $this->sizePosition($signal);
        $order = $this->executor->openPosition($signal, $symbol, $quantity);

        return Position::create([
            'symbol' => $symbol,
            'interval' => $interval,
            'direction' => $signal->direction->value,
            'signal_type' => $signal->type->value,
            'confluence' => $signal->confluence,
            'status' => Position::STATUS_OPEN,
            'entry_price' => $signal->entryPrice,
            'stop_price' => $signal->stop,
            'target1' => $signal->target1,
            'target2' => $signal->target2,
            'rr_ratio' => $signal->rrRatio,
            'quantity' => $quantity,
            'size' => 1.0,
            'entry_order_id' => $order->orderId,
            'entry_context' => [
                'signal' => $signal->toArray(),
                'indicators' => $indicators->toArray(),
                'level' => $level ?? $signal->entryPrice,
                'entry_open_time' => $entryOpenTime,
                'order' => ['ok' => $order->ok, 'error' => $order->error],
            ],
            'opened_at' => Carbon::now(),
        ]);
    }

    /**
     * Apply an exit signal to an open position: route the close, update the
     * record, and log the reason. A partial take-profit (T1) keeps the position
     * open at reduced size with the stop moved to break-even.
     */
    public function applyExit(Position $position, ExitSignal $exit, float $price): Position
    {
        $this->executor->closePosition($position->symbol, $position->direction(), $exit->closePercent);

        $context = [
            'exit' => $exit->toArray(),
            'price' => round($price, 8),
        ];

        if ($exit->type === ExitType::Target1) {
            // Partial: bank 50%, trail stop to break-even, stay open.
            $this->executor->moveStop($position->symbol, $position->direction(), $exit->moveStopTo ?? $position->entry_price);
            $position->update([
                'size' => 0.5,
                'stop_price' => $exit->moveStopTo ?? $position->entry_price,
                'exit_context' => $context,
            ]);

            return $position;
        }

        // Full exit.
        $position->update([
            'status' => Position::STATUS_CLOSED,
            'exit_type' => $exit->type->value,
            'exit_reason' => $exit->reason?->value,
            'exit_price' => $price,
            'realized_pnl' => $this->pnl($position, $price),
            'exit_context' => $context,
            'closed_at' => Carbon::now(),
        ]);

        return $position;
    }

    /**
     * Calculate position quantity from risk percent of available balance.
     *
     * quantity = (balance × risk_pct / 100) / risk_per_unit
     *
     * Falls back to min_quantity when the calculation is degenerate (zero stop
     * distance, zero balance, or risk_percent disabled).
     */
    private function sizePosition(EntrySignal $signal): float
    {
        $riskPct = (float) ($this->config['risk_percent'] ?? 1.0);
        $maxQty  = (float) ($this->config['max_quantity'] ?? 0.0);

        $riskPerUnit = abs($signal->entryPrice - $signal->stop);

        if ($riskPct <= 0.0 || $riskPerUnit <= 0.0) {
            return 0.0;
        }

        $balance = $this->executor->balance();
        if ($balance <= 0.0) {
            return 0.0;
        }

        $quantity = round(($balance * $riskPct / 100.0) / $riskPerUnit, 4);

        if ($maxQty > 0.0) {
            $quantity = min($quantity, $maxQty);
        }

        $maxPositionPct = (float) ($this->config['max_position_pct'] ?? 0.0);
        if ($maxPositionPct > 0.0 && $signal->entryPrice > 0.0) {
            $maxByNotional = round($balance * $maxPositionPct / 100.0 / $signal->entryPrice, 4);
            $quantity = min($quantity, $maxByNotional);
        }

        return $quantity;
    }

    /** Realised PnL of the remaining size at `price`. */
    private function pnl(Position $position, float $price): float
    {
        $delta = ($price - $position->entry_price) * $position->direction()->sign();

        return $delta * $position->quantity * $position->size;
    }

    private function toState(Position $position): PositionState
    {
        return new PositionState(
            direction: $position->direction(),
            entryPrice: $position->entry_price,
            stopPrice: $position->stop_price,
            target1: $position->target1,
            target2: $position->target2,
            size: $position->size,
            // A reduced size means T1 already banked profit and the stop is at break-even.
            breakevenSet: $position->size < 1.0,
        );
    }

    /**
     * Entry-signal types already opened within the last 5 bars — fed to the
     * agent so it won't duplicate a setup.
     *
     * @return list<string>
     */
    private function recentSignalTypes(string $symbol, string $interval): array
    {
        $seconds = (int) (config("exchange.timeframes.{$interval}") ?? 60);
        $since = Carbon::now()->subSeconds($seconds * 5);

        return Position::query()
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->where('opened_at', '>=', $since)
            ->pluck('signal_type')
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, \App\Market\DTO\Candle> $candles */
    private function currentPrice(array $candles): float
    {
        $last = end($candles);

        return $last ? $last->close : 0.0;
    }

    /** @param array<int, \App\Market\DTO\Candle> $candles */
    private function currentOpenTime(array $candles): ?int
    {
        $last = end($candles);

        return $last ? $last->openTime : null;
    }
}
