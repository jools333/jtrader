<?php

declare(strict_types=1);

namespace App\Trading\Agent;

use App\Market\Analysis\Support\SeriesMath;
use App\Market\DTO\Candle;
use App\Trading\Analysis\CandleSignals;
use App\Trading\Contracts\TradingAgentInterface;
use App\Trading\DTO\AgentResult;
use App\Trading\DTO\EntrySignal;
use App\Trading\DTO\ExitSignal;
use App\Trading\DTO\IndicatorSnapshot;
use App\Trading\DTO\PositionState;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitReason;
use App\Trading\Enums\ExitType;
use App\Trading\Enums\SignalType;

/**
 * Rule-based scalping agent.
 *
 * Generates entry signals from four setups (bounce, breakout-retest, false
 * breakout, trend pullback) and manages open positions with four exit rules
 * (partial at T1, full at T2, early reversal, stop-loss). All indicators
 * (EMA8/21, MACD, ATR) are computed in-process; thresholds are ATR-relative.
 *
 * The agent is deliberately side-effect free — it only reads candles and
 * returns DTOs, so it is trivially testable and reusable across exchanges.
 */
final class TradingAgent implements TradingAgentInterface
{
    /** @param array<string, mixed> $config the `config/trading.php` block */
    public function __construct(private readonly array $config = [])
    {
    }

    public function evaluate(
        array $candles,
        float $level,
        ?float $atr = null,
        ?PositionState $position = null,
        array $recentSignalTypes = [],
    ): AgentResult {
        $candles = array_values($candles);
        $n = count($candles);

        $atr = $atr ?? SeriesMath::atrSma($candles, 14);
        $closes = array_map(static fn (Candle $c) => $c->close, $candles);

        $ema8 = SeriesMath::ema($closes, 8);
        $ema21 = SeriesMath::ema($closes, 21);
        $macd = SeriesMath::macd($closes, 12, 26, 9);

        $i = $n - 1;
        $indicators = new IndicatorSnapshot(
            ema8: $ema8[$i] ?? 0.0,
            ema21: $ema21[$i] ?? 0.0,
            macdLine: $macd['line'][$i] ?? 0.0,
            macdSignal: $macd['signal'][$i] ?? 0.0,
            macdHist: $macd['histogram'][$i] ?? 0.0,
            atr: $atr,
        );

        $ctx = new RuleContext($candles, $level, $atr, $ema8, $ema21, $macd);

        $exit = $position !== null ? $this->evaluateExit($ctx, $position) : null;

        // Don't open while managing a position, and bail on degenerate input.
        $entry = ($position === null && $n >= 50 && $atr > 0.0)
            ? $this->evaluateEntry($ctx, $recentSignalTypes)
            : null;

        return new AgentResult($entry, $exit, $indicators);
    }

    private function cfg(string $key, float $default): float
    {
        return (float) ($this->config[$key] ?? $default);
    }

    // ---------------------------------------------------------------------
    // Entry
    // ---------------------------------------------------------------------

    /** @param list<string> $recentSignalTypes */
    private function evaluateEntry(RuleContext $ctx, array $recentSignalTypes): ?EntrySignal
    {
        // Global guards (spec "Ограничения и защиты").
        if ($ctx->atrTravelFraction() > $this->cfg('max_atr_travel', 0.60)) {
            return null; // price already ran > 60% of ATR away from the level
        }
        if ($ctx->recentWidth(5) < $ctx->atr * $this->cfg('min_flat_width', 0.30)) {
            return null; // last 5 candles too tight — dead flat, no edge
        }

        foreach ([
            fn () => $this->ruleBounce($ctx),
            fn () => $this->ruleRetest($ctx),
            fn () => $this->ruleFalseBreakout($ctx),
            fn () => $this->ruleTrendPullback($ctx),
        ] as $rule) {
            $signal = $rule();
            if ($signal === null) {
                continue;
            }

            // Skip duplicates and sub-2.0 R:R setups.
            if (in_array($signal->type->value, $recentSignalTypes, true)) {
                continue;
            }
            if ($signal->rrRatio < $this->cfg('min_rr', 2.0)) {
                continue;
            }

            return $signal;
        }

        return null;
    }

    /**
     * Build the trade plan (stop / targets / R:R) for a candidate entry and
     * wrap it in an {@see EntrySignal}. Stop sits beyond the level by a fraction
     * of ATR; targets are multiples of the resulting risk.
     */
    private function plan(RuleContext $ctx, SignalType $type, Direction $dir, bool $confluence = false, ?float $stopPrice = null): ?EntrySignal
    {
        $entry = $ctx->price();
        $stopBuffer = $ctx->atr * $this->cfg('stop_atr', 0.5);
        $t1Mult = $this->cfg('target1_r', 2.0);
        $t2Mult = $this->cfg('target2_r', 4.0);

        if ($dir === Direction::Long) {
            // Use provided stop, or default level-based stop, whichever is lower.
            $defaultStop = $ctx->level - $stopBuffer;
            $stop = $stopPrice !== null ? min($defaultStop, $stopPrice) : $defaultStop;
            $risk = $entry - $stop;
            if ($risk <= 0.0) {
                return null;
            }
            $target1 = $entry + $risk * $t1Mult;
            $target2 = $entry + $risk * $t2Mult;
        } else {
            // Use provided stop, or default level-based stop, whichever is higher.
            $defaultStop = $ctx->level + $stopBuffer;
            $stop = $stopPrice !== null ? max($defaultStop, $stopPrice) : $defaultStop;
            $risk = $stop - $entry;
            if ($risk <= 0.0) {
                return null;
            }
            $target1 = $entry - $risk * $t1Mult;
            $target2 = $entry - $risk * $t2Mult;
        }

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

    /** Rule 1 — bounce off a level (price rejects support/resistance). */
    private function ruleBounce(RuleContext $ctx): ?EntrySignal
    {
        $zone = $ctx->atr * 0.15;
        $last3 = $ctx->slice(3);
        if (! $ctx->allTouchZone($last3, $zone)) {
            return null;
        }
        if (CandleSignals::countCompression($last3, $ctx->atr) < 2) {
            return null;
        }

        $last = $ctx->last();
        $hist = $ctx->macd['histogram'];
        $i = $ctx->i;

        // SHORT off resistance.
        if (CandleSignals::isBearishImpulse($last, $ctx->atr)
            && ($ctx->ema8At($i) < $ctx->level || $ctx->ema8Falling())
            && ($hist[$i] < ($hist[$i - 1] ?? $hist[$i]))
            && $ctx->price() >= $ctx->level - $ctx->atr * 0.20
        ) {
            return $this->plan($ctx, SignalType::Bounce, Direction::Short);
        }

        // LONG off support.
        if (CandleSignals::isBullishImpulse($last, $ctx->atr)
            && ($ctx->ema8At($i) > $ctx->level || $ctx->ema8Rising())
            && ($hist[$i] > ($hist[$i - 1] ?? $hist[$i]))
            && $ctx->price() <= $ctx->level + $ctx->atr * 0.20
        ) {
            return $this->plan($ctx, SignalType::Bounce, Direction::Long);
        }

        return null;
    }

    /** Rule 2 — breakout then retest of the broken level. */
    private function ruleRetest(RuleContext $ctx): ?EntrySignal
    {
        $zone = $ctx->atr * 0.20;
        if (abs($ctx->price() - $ctx->level) > $zone) {
            return null; // price not back at the level
        }

        $last3 = $ctx->slice(3);
        $i = $ctx->i;
        $hasCompression = CandleSignals::countCompression($last3, $ctx->atr) >= 1;

        // LONG — broke up, retesting from above.
        if ($ctx->hadBreakoutCandle(Direction::Long, 10)
            && $ctx->ema8At($i) > $ctx->level
            && $ctx->ema8At($i) > $ctx->ema21At($i)
            && $ctx->macd['line'][$i] > 0
            && $hasCompression
            && CandleSignals::isBullishImpulse($ctx->last(), $ctx->atr)
        ) {
            return $this->plan($ctx, SignalType::Retest, Direction::Long);
        }

        // SHORT — broke down, retesting from below.
        if ($ctx->hadBreakoutCandle(Direction::Short, 10)
            && $ctx->ema8At($i) < $ctx->level
            && $ctx->ema8At($i) < $ctx->ema21At($i)
            && $ctx->macd['line'][$i] < 0
            && $hasCompression
            && CandleSignals::isBearishImpulse($ctx->last(), $ctx->atr)
        ) {
            return $this->plan($ctx, SignalType::Retest, Direction::Short);
        }

        return null;
    }

    /** Rule 3 — false breakout (wick pierces the level, body closes back). */
    private function ruleFalseBreakout(RuleContext $ctx): ?EntrySignal
    {
        if ($ctx->n < 2) {
            return null;
        }
        $penult = $ctx->candles[$ctx->i - 1];
        $last = $ctx->last();
        $atr = $ctx->atr;
        $i = $ctx->i;
        $hist = $ctx->macd['histogram'];

        // SHORT — wick above resistance, close back below.
        // Stop sits just above the wick high (natural invalidation: a close above the wick means the breakout was real).
        if ($penult->high > $ctx->level + $atr * 0.10
            && $penult->close < $ctx->level
            && ($penult->high - $ctx->level) > $atr * 0.15
            && CandleSignals::isBearishImpulse($last, $atr, 0.4)
            && ($ctx->ema8At($i) < $ctx->level || $ctx->ema8Falling())
            && ($hist[$i] < 0 || $hist[$i] < ($hist[$i - 1] ?? $hist[$i]))
        ) {
            return $this->plan($ctx, SignalType::FalseBreakout, Direction::Short, false, $penult->high + $atr * 0.10);
        }

        // LONG — wick below support, close back above.
        // Stop sits just below the wick low (natural invalidation: a close below the wick means support is broken for real).
        if ($penult->low < $ctx->level - $atr * 0.10
            && $penult->close > $ctx->level
            && ($ctx->level - $penult->low) > $atr * 0.15
            && CandleSignals::isBullishImpulse($last, $atr, 0.4)
            && ($ctx->ema8At($i) > $ctx->level || $ctx->ema8Rising())
            && ($hist[$i] > 0 || $hist[$i] > ($hist[$i - 1] ?? $hist[$i]))
        ) {
            return $this->plan($ctx, SignalType::FalseBreakout, Direction::Long, false, $penult->low - $atr * 0.10);
        }

        return null;
    }

    /** Rule 4 — pullback to a level in the direction of an established trend. */
    private function ruleTrendPullback(RuleContext $ctx): ?EntrySignal
    {
        $zone = $ctx->atr * 0.15;
        if (abs($ctx->price() - $ctx->level) > $zone) {
            return null;
        }
        if (abs($ctx->atrTravelSigned()) >= 0.20) {
            return null; // more than 20% of ATR consumed off the level
        }

        $last3 = $ctx->slice(3);
        if (CandleSignals::countCompression($last3, $ctx->atr) < 1) {
            return null;
        }

        $i = $ctx->i;
        $confluence = abs($ctx->ema21At($i) - $ctx->level) < $ctx->atr * 0.20;

        // LONG — up-trend, pullback to support.
        if ($ctx->emaTrend(Direction::Long, 5)
            && CandleSignals::isBullishImpulse($ctx->last(), $ctx->atr)
            && ! $ctx->bearishDivergence()
        ) {
            return $this->plan($ctx, SignalType::TrendPullback, Direction::Long, $confluence);
        }

        // SHORT — down-trend, pullback to resistance.
        if ($ctx->emaTrend(Direction::Short, 5)
            && CandleSignals::isBearishImpulse($ctx->last(), $ctx->atr)
            && ! $ctx->bullishDivergence()
        ) {
            return $this->plan($ctx, SignalType::TrendPullback, Direction::Short, $confluence);
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Exit
    // ---------------------------------------------------------------------

    /**
     * Evaluate exits for an open position, in priority order:
     * stop-loss (capital first) > full take-profit (T2) > partial (T1) >
     * early reversal. At most one exit is returned per bar.
     */
    private function evaluateExit(RuleContext $ctx, PositionState $pos): ?ExitSignal
    {
        $price = $ctx->price();
        $long = $pos->direction === Direction::Long;

        // Rule 4 — stop-loss (uses break-even stop once T1 has banked profit).
        $stop = $pos->breakevenSet ? $pos->entryPrice : $pos->stopPrice;
        if (($long && $price <= $stop) || (! $long && $price >= $stop)) {
            return new ExitSignal(ExitType::StopLoss, 100);
        }

        // Rule 2 — full exit at target 2.
        if (($long && $price >= $pos->target2) || (! $long && $price <= $pos->target2)) {
            return new ExitSignal(ExitType::Target2, 100);
        }

        // Rule 1 — partial (50%) at target 1, move stop to break-even.
        if (! $pos->breakevenSet
            && (($long && $price >= $pos->target1) || (! $long && $price <= $pos->target1))
        ) {
            return new ExitSignal(ExitType::Target1, 50, moveStopTo: $pos->entryPrice);
        }

        // Rule 3 — early exit on a reversal signal against the position.
        return $this->ruleEarlyReversal($ctx, $pos);
    }

    /** Rule 3 — close 100% when a reversal pattern forms against the position. */
    private function ruleEarlyReversal(RuleContext $ctx, PositionState $pos): ?ExitSignal
    {
        if ($ctx->n < 3) {
            return null;
        }
        $long = $pos->direction === Direction::Long;
        $atr = $ctx->atr;
        $i = $ctx->i;

        // Two consecutive compression candles directly before the last bar.
        if (! CandleSignals::isCompression($ctx->candles[$i - 1], $atr)
            || ! CandleSignals::isCompression($ctx->candles[$i - 2], $atr)
        ) {
            return null;
        }

        // Last candle is a counter-position impulse.
        $impulse = $long
            ? CandleSignals::isBearishImpulse($ctx->last(), $atr)
            : CandleSignals::isBullishImpulse($ctx->last(), $atr);
        if (! $impulse) {
            return null;
        }

        $reason = $this->reversalReason($ctx, $long);
        if ($reason === null) {
            return null;
        }

        return new ExitSignal(ExitType::EarlyReversal, 100, reason: $reason);
    }

    /** First confirming reason among divergence / absorption / EMA turn. */
    private function reversalReason(RuleContext $ctx, bool $long): ?ExitReason
    {
        $i = $ctx->i;
        $last = $ctx->last();
        $atr = $ctx->atr;

        if ($long ? $ctx->bearishDivergence() : $ctx->bullishDivergence()) {
            return ExitReason::Divergence;
        }

        // Effort without result: heavy volume, tiny body (absorption).
        if ($last->volume > CandleSignals::avgVolume($ctx->candles) * 1.5
            && CandleSignals::body($last) < $atr * 0.20
        ) {
            return ExitReason::Absorption;
        }

        // EMA8 turned against the position over the last 3 values.
        $turn = $long
            ? ($ctx->ema8At($i) < $ctx->ema8At($i - 1) && $ctx->ema8At($i - 1) < $ctx->ema8At($i - 2))
            : ($ctx->ema8At($i) > $ctx->ema8At($i - 1) && $ctx->ema8At($i - 1) > $ctx->ema8At($i - 2));
        if ($turn) {
            return ExitReason::EmaTurn;
        }

        return null;
    }
}
