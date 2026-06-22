<?php

declare(strict_types=1);

namespace App\Trading\Execution;

use App\Trading\Contracts\TradeExecutorInterface;
use App\Trading\DTO\EntrySignal;
use App\Trading\Enums\Direction;
use Psr\Log\LoggerInterface;

/**
 * Safe default executor: routes nothing to a real venue, just logs the
 * intended action and returns success. Lets the whole pipeline (signal →
 * position record → "fill") run end-to-end without API keys or network — the
 * dev sandbox can't reach the exchange anyway.
 */
final class PaperTradeExecutor implements TradeExecutorInterface
{
    public function __construct(private readonly LoggerInterface $log)
    {
    }

    public function name(): string
    {
        return 'Paper';
    }

    public function openPosition(EntrySignal $signal, string $symbol, float $quantity): OrderResult
    {
        $this->log->info('[paper] open', [
            'symbol' => $symbol,
            'qty' => $quantity,
            'signal' => $signal->toArray(),
        ]);

        return OrderResult::success('paper-'.uniqid('', true), $signal->toArray());
    }

    public function closePosition(string $symbol, Direction $direction, int $percent): OrderResult
    {
        $this->log->info('[paper] close', compact('symbol', 'percent') + ['direction' => $direction->value]);

        return OrderResult::success('paper-'.uniqid('', true));
    }

    public function moveStop(string $symbol, Direction $direction, float $newStop): OrderResult
    {
        $this->log->info('[paper] move stop', compact('symbol', 'newStop') + ['direction' => $direction->value]);

        return OrderResult::success();
    }
}
