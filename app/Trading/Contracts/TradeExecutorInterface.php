<?php

declare(strict_types=1);

namespace App\Trading\Contracts;

use App\Trading\DTO\EntrySignal;
use App\Trading\Enums\Direction;
use App\Trading\Execution\OrderResult;

/**
 * Order-routing abstraction. The agent decides *what* to do; an executor
 * carries it out on a venue (or simulates it). Swapping exchanges means adding
 * an implementation and rebinding it in the service provider — no rule code
 * changes. Mirrors the {@see \App\Market\Contracts\ExchangeInterface} pattern
 * for market data, on the order-management side.
 */
interface TradeExecutorInterface
{
    /** Human-readable executor name, e.g. "BingX" or "Paper". */
    public function name(): string;

    /**
     * Open a position for `signal` with the given quantity, attaching the
     * stop-loss and (where supported) take-profit targets from the signal.
     */
    public function openPosition(EntrySignal $signal, string $symbol, float $quantity): OrderResult;

    /**
     * Close `percent`% (1–100) of the open position on `symbol`.
     */
    public function closePosition(string $symbol, Direction $direction, int $percent): OrderResult;

    /**
     * Relocate the protective stop (e.g. to break-even after a partial exit).
     */
    public function moveStop(string $symbol, Direction $direction, float $newStop): OrderResult;

    /**
     * Available balance (in quote currency, e.g. USDT) that can be risked.
     * Paper executors return a configured constant; live executors query the venue.
     */
    public function balance(): float;
}
