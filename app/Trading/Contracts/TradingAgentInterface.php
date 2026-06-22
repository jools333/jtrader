<?php

declare(strict_types=1);

namespace App\Trading\Contracts;

use App\Market\DTO\Candle;
use App\Trading\DTO\AgentResult;
use App\Trading\DTO\PositionState;

/**
 * Decision surface of the trading agent: given candles, a key level and ATR,
 * decide whether to open a position and/or how to manage an open one.
 *
 * Implementations are pure (no I/O): persistence and order routing live in the
 * orchestration layer ({@see \App\Trading\Execution\PositionManager}).
 */
interface TradingAgentInterface
{
    /**
     * Evaluate one bar's worth of state.
     *
     * @param array<int, Candle> $candles Oldest -> newest, at least 50 expected.
     * @param float $level Key horizontal level (support or resistance) under test.
     * @param float|null $atr ATR(14) for the instrument; computed from candles if null.
     * @param PositionState|null $position Open position to manage, or null when flat.
     * @param list<string> $recentSignalTypes Entry-signal types already emitted within
     *        the last 5 candles — used to avoid duplicate entries.
     */
    public function evaluate(
        array $candles,
        float $level,
        ?float $atr = null,
        ?PositionState $position = null,
        array $recentSignalTypes = [],
    ): AgentResult;
}
