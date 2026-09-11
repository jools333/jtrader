<?php

declare(strict_types=1);

namespace App\Trading\Execution;

use App\Jobs\RenderPositionChartJob;
use App\Market\DTO\Candle;
use App\Models\Position;
use App\Services\Telegram\TelegramService;
use App\Trading\Charting\ChartRenderer;
use App\Trading\Contracts\TradeExecutorInterface;
use App\Trading\Contracts\TradingAgentInterface;
use App\Trading\DTO\AgentResult;
use App\Trading\DTO\EntrySignal;
use App\Trading\DTO\ExitSignal;
use App\Trading\DTO\IndicatorSnapshot;
use App\Trading\DTO\PositionState;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitType;
use App\Trading\Services\DailyPositionReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        private readonly ?TelegramService $telegram = null,
        private readonly ?DailyPositionReportService $reportService = null,
    ) {}

    /**
     * Evaluate and act for one bar. Returns the agent result so callers can log
     * or display it.
     *
     * @param  array<int, Candle>  $candles  oldest -> newest
     */
    public function process(
        string $symbol,
        string $interval,
        array $candles,
        float $level,
        ?float $atr = null,
        ?array $btcCandles = null,
        ?array $btcHtfCandles = null,
    ): AgentResult {
        $open = $this->openPosition($symbol, $interval);

        $state = $open !== null ? $this->toState($open) : null;
        $recent = $open !== null ? [] : $this->recentSignalTypes($symbol, $interval);

        $result = $this->agent->evaluate($candles, $level, $atr, $state, $recent, $symbol, $interval, $btcCandles, $btcHtfCandles);

        $excluded = (array) ($this->config['excluded_symbols'] ?? []);
        $isExcluded = in_array($symbol, $excluded, true);

        if ($open !== null && $result->exitSignal !== null) {
            $position = $this->applyExit($open, $result->exitSignal, $this->currentPrice($candles));
            $this->attachChart($position, $candles);
        } elseif ($open !== null) {
            $this->manageDynamicProtection($open, $this->currentPrice($candles));
        } elseif ($open === null
            && $result->entrySignal !== null
            && ! $isExcluded
            && ! $this->hasReachedMaxOpenPositions()
            && ! $this->isCoolingDown($symbol)
            && ! $this->isDailyLossLimitReached()
        ) {
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
     * @param  array<int, Candle>  $candles
     */
    private function attachChart(Position $position, array $candles): void
    {
        if ($this->chart === null) {
            return;
        }

        $chartEnabled = (bool) ($this->config['chart']['enabled'] ?? config('trading.chart.enabled', false));
        if (! $chartEnabled) {
            return;
        }

        if ((bool) config('trading.chart.queue', true)) {
            RenderPositionChartJob::dispatch($position->id, $candles);

            return;
        }

        $path = $this->chart->render($position, $candles);
        if ($path !== null) {
            $position->update(['chart_path' => $path]);
        }
    }

    /** The currently open position for the pair, if any (at most 1 active position per symbol). */
    public function openPosition(string $symbol, string $interval): ?Position
    {
        return Position::query()
            ->open()
            ->where('symbol', $symbol)
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
        $quantity = $this->sizePosition($signal, $symbol);
        $order = $this->executor->openPosition($signal, $symbol, $quantity);

        return Position::create([
            'symbol' => $symbol,
            'interval' => $interval,
            'direction' => $signal->direction->value,
            'signal_type' => $signal->type->value,
            'confluence' => $signal->confluence,
            'status' => Position::STATUS_OPEN,
            'entry_price' => $order->filledPrice() ?? $signal->entryPrice,
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
        $closeOrder = $this->executor->closePosition($position->symbol, $position->direction(), $exit->closePercent);
        $filledAt = $closeOrder->filledPrice() ?? $price;

        $context = [
            'exit' => $exit->toArray(),
            'price' => round($filledAt, 8),
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
            'exit_price' => $filledAt,
            'realized_pnl' => $this->pnl($position, $filledAt),
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
    private function sizePosition(EntrySignal $signal, ?string $symbol = null): float
    {
        $symbolRisk = ($symbol !== null && isset($this->config['symbol_risk_pct'][$symbol]))
            ? (float) $this->config['symbol_risk_pct'][$symbol]
            : null;
        $riskPct = $symbolRisk ?? (float) ($this->config['risk_percent'] ?? 1.0);
        $maxQty = (float) ($this->config['max_quantity'] ?? 0.0);

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

        $symbolMaxPos = ($symbol !== null && isset($this->config['symbol_max_position_pct'][$symbol]))
            ? (float) $this->config['symbol_max_position_pct'][$symbol]
            : null;
        $maxPositionPct = $symbolMaxPos ?? (float) ($this->config['max_position_pct'] ?? 0.0);
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
            openedAt: $position->opened_at,
        );
    }

    /**
     * Whether the maximum number of concurrent open positions across all symbols has been reached.
     */
    public function hasReachedMaxOpenPositions(): bool
    {
        $max = (int) ($this->config['max_open_positions'] ?? 0);
        if ($max <= 0) {
            return false;
        }

        $openCount = Position::query()->open()->count();
        if ($openCount >= $max) {
            Log::info("[risk_guard] Maximum open positions limit reached ({$openCount}/{$max}). Skipping entry.");

            return true;
        }

        return false;
    }

    /**
     * Whether this symbol has had an opened or closed position within the cooldown window.
     * If the most recent closed position was stopped out (STOP_LOSS), uses stop_loss_cooldown_minutes.
     */
    public function isCoolingDown(string $symbol): bool
    {
        $minutes = (int) ($this->config['entry_cooldown_minutes'] ?? 0);
        if ($minutes > 0) {
            $since = Carbon::now()->subMinutes($minutes);
            $hasRecent = Position::query()
                ->where('symbol', $symbol)
                ->where(function ($q) use ($since) {
                    $q->where('opened_at', '>=', $since)
                        ->orWhere('closed_at', '>=', $since);
                })
                ->exists();

            if ($hasRecent) {
                return true;
            }
        }

        $slMinutes = (int) ($this->config['stop_loss_cooldown_minutes'] ?? 0);
        if ($slMinutes > 0 && $slMinutes > $minutes) {
            $slSince = Carbon::now()->subMinutes($slMinutes);
            $hasRecentSl = Position::query()
                ->where('symbol', $symbol)
                ->where('closed_at', '>=', $slSince)
                ->where(function ($q) {
                    $q->where('exit_type', ExitType::StopLoss->value)
                        ->orWhere('exit_reason', 'stop_loss_hit');
                })
                ->exists();

            if ($hasRecentSl) {
                Log::info("[risk_guard] Symbol {$symbol} is in extended stop-loss cooldown ({$slMinutes}m). Skipping entry.");

                return true;
            }
        }

        return false;
    }

    /**
     * Whether the net closed PnL for the current calendar day has reached or exceeded the daily loss limit.
     */
    public function isDailyLossLimitReached(): bool
    {
        $limit = (float) ($this->config['daily_loss_limit'] ?? 0.0);
        if ($limit <= 0.0) {
            return false;
        }

        $tz = (string) config('services.telegram.report_timezone', config('app.timezone', 'UTC'));
        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay()->utc();
        $todayEnd = $now->copy()->endOfDay()->utc();

        $positions = Position::query()
            ->where('status', Position::STATUS_CLOSED)
            ->whereBetween('closed_at', [$todayStart, $todayEnd])
            ->get();

        if ($positions->isEmpty()) {
            return false;
        }

        $reportService = $this->reportService ?? (app()->bound(DailyPositionReportService::class) ? app(DailyPositionReportService::class) : null);
        $dedup = $reportService ? $reportService->deduplicatePositions($positions) : $positions;

        $netPnl = 0.0;
        foreach ($dedup as $pos) {
            $netPnl += $pos->netPnl() ?? 0.0;
        }

        $maxAllowedLoss = -abs($limit);
        if ($netPnl <= $maxAllowedLoss) {
            Log::warning(sprintf(
                '[circuit_breaker] Daily loss limit reached: Net PnL %.2f USDT <= %.2f USDT (limit: %.2f USDT). Pausing entries.',
                $netPnl,
                $maxAllowedLoss,
                $limit
            ));

            $this->notifyDailyLossCircuitBreaker($netPnl, $limit, $now->format('Y-m-d'));

            return true;
        }

        return false;
    }

    /**
     * Send a one-time Telegram alert when the daily loss circuit breaker triggers.
     */
    private function notifyDailyLossCircuitBreaker(float $netPnl, float $limit, string $dateStr): void
    {
        $cacheKey = "circuit_breaker_alert_{$dateStr}";
        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, Carbon::now()->endOfDay());

        try {
            $telegram = $this->telegram ?? (app()->bound(TelegramService::class) ? app(TelegramService::class) : null);
            if ($telegram && $telegram->isConfigured()) {
                $pnlFormatted = number_format($netPnl, 2, '.', '');
                $limitFormatted = number_format($limit, 2, '.', '');
                $message = "🚨 <b>Сработал дневной стоп-лосс (Circuit Breaker)!</b>\n\n".
                    "Чистый убыток за сегодня достиг <code>{$pnlFormatted} USDT</code> при лимите <code>-{$limitFormatted} USDT</code>.\n".
                    'Открытие новых позиций временно приостановлено до конца суток для защиты депозита.';

                $telegram->sendMessage($message);
            }
        } catch (Throwable $e) {
            Log::error('[circuit_breaker] Failed to send Telegram alert: '.$e->getMessage());
        }
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

    /** @param array<int, Candle> $candles */
    private function currentPrice(array $candles): float
    {
        $last = end($candles);

        return $last ? $last->close : 0.0;
    }

    /** @param array<int, Candle> $candles */
    private function currentOpenTime(array $candles): ?int
    {
        $last = end($candles);

        return $last ? $last->openTime : null;
    }

    /**
     * Manages Break-Even and Trailing Stop protection for an active open position.
     */
    public function manageDynamicProtection(Position $position, float $price): ?float
    {
        if ($price <= 0.0 || $position->entry_price <= 0.0) {
            return null;
        }

        $agentConfig = (array) ($this->config['agent'] ?? []);
        $isLong = $position->direction() === Direction::Long;
        $entry = $position->entry_price;

        // Calculate current unrealized profit percentage
        $profitPct = $isLong
            ? (($price - $entry) / $entry) * 100.0
            : (($entry - $price) / $entry) * 100.0;

        $newStop = null;
        $reason = null;

        // 1. Trailing Stop Check (Highest Priority Protection when profit >= trailing_trigger_pct)
        $trailingEnabled = (bool) ($agentConfig['trailing_stop_enabled'] ?? $this->config['trailing_stop_enabled'] ?? true);
        $trailingTrigger = (float) ($agentConfig['trailing_trigger_pct'] ?? $this->config['trailing_trigger_pct'] ?? 0.40);
        $trailingDistance = (float) ($agentConfig['trailing_distance_pct'] ?? $this->config['trailing_distance_pct'] ?? 0.20) / 100.0;

        if ($trailingEnabled && $profitPct >= $trailingTrigger) {
            $candidateTrailingStop = $isLong
                ? $price * (1.0 - $trailingDistance)
                : $price * (1.0 + $trailingDistance);

            // For Long, trailing stop must be higher than current stop
            // For Short, trailing stop must be lower than current stop
            $isBetter = $isLong
                ? ($candidateTrailingStop > $position->stop_price)
                : ($candidateTrailingStop < $position->stop_price);

            if ($isBetter) {
                $newStop = $candidateTrailingStop;
                $reason = 'trailing_stop';
            }
        }

        // 2. Break-Even Check (if trailing didn't trigger, or trailing stop is worse than BE)
        $beEnabled = (bool) ($agentConfig['break_even_enabled'] ?? $this->config['break_even_enabled'] ?? true);
        $beTrigger = (float) ($agentConfig['break_even_trigger_pct'] ?? $this->config['break_even_trigger_pct'] ?? 0.25);
        $beBuffer = (float) ($agentConfig['break_even_buffer_pct'] ?? $this->config['break_even_buffer_pct'] ?? 0.11) / 100.0;

        if ($newStop === null && $beEnabled && $profitPct >= $beTrigger) {
            $candidateBeStop = $isLong
                ? $entry * (1.0 + $beBuffer)
                : $entry * (1.0 - $beBuffer);

            $isBetter = $isLong
                ? ($candidateBeStop > $position->stop_price)
                : ($candidateBeStop < $position->stop_price);

            if ($isBetter) {
                $newStop = $candidateBeStop;
                $reason = 'break_even';
            }
        }

        if ($newStop === null) {
            return null;
        }

        // 3. Minimum shift threshold check (avoid spamming exchange API for micro-shifts)
        $minShiftPct = (float) ($agentConfig['protection_min_shift_pct'] ?? $this->config['protection_min_shift_pct'] ?? 0.03) / 100.0;
        $shiftPct = abs($newStop - $position->stop_price) / $position->entry_price;
        if ($shiftPct < $minShiftPct) {
            return null;
        }

        // Round stop price to appropriate decimals
        $newStop = round($newStop, $this->priceDecimals($position->entry_price));

        Log::info(sprintf(
            '[dynamic_protection] Relocating stop for %s %s: %.6f -> %.6f (%s, profit: %.2f%%, price: %.6f)',
            $position->symbol,
            $position->direction,
            $position->stop_price,
            $newStop,
            $reason,
            $profitPct,
            $price
        ));

        // Relocate stop on exchange
        $this->executor->moveStop($position->symbol, $position->direction(), $newStop);

        // Update stop in database
        $exitContext = (array) ($position->exit_context ?? []);
        $exitContext['protection'] = [
            'reason' => $reason,
            'profit_pct' => round($profitPct, 3),
            'updated_at' => Carbon::now()->toIso8601String(),
        ];

        $position->update([
            'stop_price' => $newStop,
            'exit_context' => $exitContext,
        ]);

        return $newStop;
    }

    private function priceDecimals(float $price): int
    {
        if ($price >= 100) {
            return 2;
        }
        if ($price >= 1) {
            return 4;
        }

        return 6;
    }
}
