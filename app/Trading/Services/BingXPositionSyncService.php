<?php

declare(strict_types=1);

namespace App\Trading\Services;

use App\Models\Position;
use App\Trading\Charting\ChartRenderer;
use App\Trading\DTO\PositionSyncResult;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitType;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronizes trading positions, fees, and execution prices with BingX perpetual swap API.
 */
class BingXPositionSyncService
{
    /**
     * @param array<string, mixed> $config Exchange bingx driver configuration
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config = [],
        private readonly ?ChartRenderer $chart = null,
    ) {
    }

    public function sync(?string $targetSymbol = null, int $lookbackDays = 3, bool $dryRun = false): PositionSyncResult
    {
        $result = new PositionSyncResult();

        if (empty($this->config['api_key']) || empty($this->config['api_secret'])) {
            $result->messages[] = 'BingX API credentials are not configured.';

            return $result;
        }

        // 1. Fetch active open positions from BingX
        $bingxOpenPositions = $this->fetchOpenPositions($targetSymbol);

        // Index BingX positions by "SYMBOL:DIRECTION"
        $bingxOpenMap = [];
        foreach ($bingxOpenPositions as $bPos) {
            $sym = (string) ($bPos['symbol'] ?? '');
            $side = strtoupper((string) ($bPos['positionSide'] ?? ''));
            if ($sym !== '' && in_array($side, ['LONG', 'SHORT'], true)) {
                $bingxOpenMap["{$sym}:{$side}"] = $bPos;
            }
        }

        // 2. Fetch local open positions
        $localOpenPositions = Position::query()
            ->open()
            ->when($targetSymbol, fn ($q) => $q->where('symbol', $targetSymbol))
            ->get();

        $localOpenMap = [];
        foreach ($localOpenPositions as $lPos) {
            $localOpenMap["{$lPos->symbol}:{$lPos->direction}"] = $lPos;
        }

        // 3. Reconcile: BingX open positions vs Local open positions
        foreach ($bingxOpenMap as $key => $bPos) {
            $sym = (string) $bPos['symbol'];
            $dir = strtoupper((string) $bPos['positionSide']);
            $amt = abs((float) ($bPos['positionAmt'] ?? 0.0));
            $entryPrice = (float) ($bPos['entryPrice'] ?? 0.0);
            $leverage = isset($bPos['leverage']) ? (int) $bPos['leverage'] : null;
            $extId = isset($bPos['positionId']) ? (string) $bPos['positionId'] : (isset($bPos['positionID']) ? (string) $bPos['positionID'] : null);

            if (isset($localOpenMap[$key])) {
                // Existing open position: update quantities, entry price, and leverage if changed
                /** @var Position $lPos */
                $lPos = $localOpenMap[$key];
                $changed = false;

                if (abs($lPos->quantity - $amt) > 0.0001) {
                    $lPos->quantity = $amt;
                    $changed = true;
                }
                if ($entryPrice > 0.0 && abs($lPos->entry_price - $entryPrice) > 0.00001) {
                    $lPos->entry_price = $entryPrice;
                    $changed = true;
                }
                if ($leverage !== null && $lPos->leverage !== $leverage) {
                    $lPos->leverage = $leverage;
                    $changed = true;
                }
                if ($extId !== null && $lPos->external_id !== $extId) {
                    $lPos->external_id = $extId;
                    $changed = true;
                }

                $lPos->synced_at = now();
                if (! $dryRun) {
                    $lPos->save();
                }

                if ($changed) {
                    $result->updated++;
                    $result->messages[] = "Updated open position {$sym} {$dir}: qty={$amt}, entry={$entryPrice}";
                }
            } else {
                // Position exists on BingX but missing locally: import it!
                $openedAt = isset($bPos['updateTime'])
                    ? Carbon::createFromTimestampMs((int) $bPos['updateTime'])
                    : now();

                if (! $dryRun) {
                    Position::create([
                        'symbol' => $sym,
                        'interval' => (string) config('exchange.default_timeframe', '5m'),
                        'direction' => $dir,
                        'signal_type' => 'EXTERNAL',
                        'status' => Position::STATUS_OPEN,
                        'entry_price' => $entryPrice,
                        'stop_price' => 0.0,
                        'target1' => 0.0,
                        'target2' => 0.0,
                        'quantity' => $amt,
                        'size' => 1.0,
                        'leverage' => $leverage,
                        'external_id' => $extId,
                        'opened_at' => $openedAt,
                        'synced_at' => now(),
                    ]);
                }

                $result->imported++;
                $result->messages[] = "Imported external open position {$sym} {$dir}: qty={$amt}, entry={$entryPrice}";
            }
        }

        // 4. Local positions that are marked OPEN, but NO LONGER OPEN on BingX (closed externally)
        foreach ($localOpenMap as $key => $lPos) {
            if (! isset($bingxOpenMap[$key])) {
                // Position was closed on BingX!
                $closeData = $this->resolveClosedPositionData($lPos, $lookbackDays);

                if (! $dryRun) {
                    $lPos->update([
                        'status' => Position::STATUS_CLOSED,
                        'exit_price' => $closeData['exit_price'],
                        'realized_pnl' => $closeData['realized_pnl'],
                        'commission' => $closeData['commission'],
                        'funding_fee' => $closeData['funding_fee'],
                        'exit_type' => $closeData['exit_type'],
                        'exit_reason' => $closeData['exit_reason'],
                        'exit_order_id' => $closeData['exit_order_id'],
                        'closed_at' => $closeData['closed_at'],
                        'synced_at' => now(),
                    ]);
                }

                $result->closed++;
                $result->messages[] = sprintf(
                    'Detected closed position %s %s: exit=%.4f, pnl=%.4f, fee=%.4f (%s)',
                    $lPos->symbol,
                    $lPos->direction,
                    $closeData['exit_price'] ?? 0.0,
                    $closeData['realized_pnl'] ?? 0.0,
                    $closeData['commission'] ?? 0.0,
                    $closeData['exit_type'] ?? 'MARKET'
                );
            }
        }

        // 5. Update commissions / exact PnL for recently closed positions that were not synced yet or have 0 fees
        $recentlyClosed = Position::query()
            ->where('status', Position::STATUS_CLOSED)
            ->where(function ($q) {
                $q->whereNull('synced_at')
                    ->orWhere('commission', 0.0);
            })
            ->where('closed_at', '>=', now()->subDays($lookbackDays))
            ->when($targetSymbol, fn ($q) => $q->where('symbol', $targetSymbol))
            ->get();

        foreach ($recentlyClosed as $cPos) {
            $closeData = $this->resolveClosedPositionData($cPos, $lookbackDays);
            $changed = false;
            if ($closeData['realized_pnl'] !== null && abs(($cPos->realized_pnl ?? 0.0) - $closeData['realized_pnl']) > 0.0001) {
                $cPos->realized_pnl = $closeData['realized_pnl'];
                $changed = true;
            }
            if ($closeData['commission'] > 0.0 && abs(($cPos->commission ?? 0.0) - $closeData['commission']) > 0.0001) {
                $cPos->commission = $closeData['commission'];
                $changed = true;
            }
            if ($closeData['funding_fee'] != 0.0 && abs(($cPos->funding_fee ?? 0.0) - $closeData['funding_fee']) > 0.0001) {
                $cPos->funding_fee = $closeData['funding_fee'];
                $changed = true;
            }
            if ($closeData['exit_price'] !== null && abs(($cPos->exit_price ?? 0.0) - $closeData['exit_price']) > 0.0001) {
                $cPos->exit_price = $closeData['exit_price'];
                $changed = true;
            }
            if ($closeData['exit_order_id'] && $cPos->exit_order_id !== $closeData['exit_order_id']) {
                $cPos->exit_order_id = $closeData['exit_order_id'];
                $changed = true;
            }
            if ($closeData['exit_type'] && $cPos->exit_type !== $closeData['exit_type']) {
                $cPos->exit_type = $closeData['exit_type'];
                $changed = true;
            }

            $cPos->synced_at = now();
            if (! $dryRun) {
                $cPos->save();
            }

            if ($changed) {
                $result->updated++;
                $result->messages[] = sprintf(
                    'Refreshed exchange fees for closed position %s #%d: exit=%.4f, pnl=%.4f, fee=%.4f',
                    $cPos->symbol,
                    $cPos->id,
                    $cPos->exit_price ?? 0.0,
                    $cPos->realized_pnl ?? 0.0,
                    $cPos->commission ?? 0.0
                );
            }
        }

        // 6. Reconcile and import past positions directly from BingX order history
        $symbols = $targetSymbol ? [$targetSymbol] : (array) config('exchange.pairs', []);
        foreach ($symbols as $sym) {
            $this->reconcileOrdersForSymbol($sym, $lookbackDays, $dryRun, $result);
        }

        return $result;
    }

    /**
     * Inspect order history for a symbol, grouping by positionID to import any missing trades.
     */
    private function reconcileOrdersForSymbol(string $symbol, int $lookbackDays, bool $dryRun, PositionSyncResult $result): void
    {
        $startTime = (int) now()->subDays($lookbackDays)->getTimestampMs();
        $orders = $this->fetchOrders($symbol, $startTime);
        if (empty($orders)) {
            return;
        }

        // Group filled orders by positionID
        $byPositionId = [];
        foreach ($orders as $order) {
            if (($order['status'] ?? '') !== 'FILLED') {
                continue;
            }
            $posId = ! empty($order['positionID']) ? (string) $order['positionID'] : null;
            if ($posId === null || $posId === '0') {
                continue;
            }
            $byPositionId[$posId][] = $order;
        }

        foreach ($byPositionId as $posId => $posOrders) {
            $openingOrder = null;
            $closingOrder = null;
            $totalCommission = 0.0;
            $realizedPnl = 0.0;
            $dir = null;

            foreach ($posOrders as $o) {
                $posSide = strtoupper((string) ($o['positionSide'] ?? ''));
                if (in_array($posSide, ['LONG', 'SHORT'], true)) {
                    $dir = $posSide;
                }
                $side = strtoupper((string) ($o['side'] ?? ''));
                $isOpeningSide = ($dir === 'LONG' && $side === 'BUY') || ($dir === 'SHORT' && $side === 'SELL');
                $totalCommission += abs((float) ($o['commission'] ?? 0.0));

                if (! empty($o['profit']) && (float) $o['profit'] != 0.0) {
                    $realizedPnl = (float) $o['profit'];
                }

                $isClosing = ! empty($o['reduceOnly'])
                    || ((float) ($o['profit'] ?? 0.0) != 0.0)
                    || in_array($o['type'] ?? '', ['TAKE_PROFIT_MARKET', 'STOP_MARKET', 'STOP', 'TAKE_PROFIT'], true)
                    || (! $isOpeningSide);

                if ($isClosing) {
                    if ($closingOrder === null || (int) ($o['updateTime'] ?? $o['time'] ?? 0) > (int) ($closingOrder['updateTime'] ?? $closingOrder['time'] ?? 0)) {
                        $closingOrder = $o;
                    }
                } else {
                    if ($openingOrder === null || (int) ($o['time'] ?? 0) < (int) ($openingOrder['time'] ?? 0)) {
                        $openingOrder = $o;
                    }
                }
            }

            if ($dir === null && $openingOrder !== null) {
                $dir = strtoupper((string) ($openingOrder['positionSide'] ?? ''));
            }
            if (! in_array($dir, ['LONG', 'SHORT'], true)) {
                continue;
            }

            // Check if already in DB
            $existing = Position::query()
                ->where('external_id', $posId)
                ->orWhere(function ($q) use ($openingOrder, $closingOrder) {
                    if ($openingOrder && ! empty($openingOrder['orderId'])) {
                        $q->where('entry_order_id', (string) $openingOrder['orderId']);
                    }
                    if ($closingOrder && ! empty($closingOrder['orderId'])) {
                        $q->orWhere('exit_order_id', (string) $closingOrder['orderId']);
                    }
                })
                ->first();

            $openedAt = $openingOrder && ! empty($openingOrder['time'])
                ? Carbon::createFromTimestampMs((int) $openingOrder['time'])
                : now();
            $closedAt = $closingOrder && ! empty($closingOrder['updateTime'] ?? $closingOrder['time'])
                ? Carbon::createFromTimestampMs((int) ($closingOrder['updateTime'] ?? $closingOrder['time']))
                : null;

            $entryPrice = (float) ($openingOrder['avgPrice'] ?? $openingOrder['price'] ?? 0.0);
            $qty = abs((float) ($openingOrder['executedQty'] ?? $openingOrder['origQty'] ?? 0.0));
            $exitPrice = $closingOrder ? (float) ($closingOrder['avgPrice'] ?? $closingOrder['price'] ?? 0.0) : null;
            $leverage = $openingOrder && ! empty($openingOrder['leverage']) ? (int) filter_var($openingOrder['leverage'], FILTER_SANITIZE_NUMBER_INT) : null;

            $exitType = null;
            $exitReason = null;
            if ($closingOrder) {
                $rawType = (string) ($closingOrder['type'] ?? 'MARKET');
                if (in_array($rawType, ['TAKE_PROFIT_MARKET', 'TAKE_PROFIT'], true)) {
                    $exitType = ExitType::Target1->value;
                    $exitReason = 'take_profit_hit';
                } elseif (in_array($rawType, ['STOP_MARKET', 'STOP'], true)) {
                    $exitType = ExitType::StopLoss->value;
                    $exitReason = 'stop_loss_hit';
                } else {
                    $exitType = 'MARKET';
                    $exitReason = 'exchange_closed';
                }
            }

            if ($existing) {
                $changed = false;
                if ($existing->commission == 0.0 && $totalCommission > 0.0) {
                    $existing->commission = $totalCommission;
                    $changed = true;
                }
                if ($closingOrder && $existing->status !== Position::STATUS_CLOSED) {
                    $existing->status = Position::STATUS_CLOSED;
                    $existing->exit_price = $exitPrice;
                    $existing->realized_pnl = $realizedPnl;
                    $existing->closed_at = $closedAt;
                    $existing->exit_type = $exitType;
                    $existing->exit_reason = $exitReason;
                    $existing->exit_order_id = (string) $closingOrder['orderId'];
                    $changed = true;
                }
                if ($existing->external_id !== $posId) {
                    $existing->external_id = $posId;
                    $changed = true;
                }
                if ($changed) {
                    $existing->synced_at = now();
                    if (! $dryRun) {
                        $existing->save();
                    }
                    $result->updated++;
                    $result->messages[] = "Updated position from order history: {$symbol} #{$existing->id}";
                }
            } else {
                // Completely new position to import!
                if (! $dryRun) {
                    Position::create([
                        'symbol' => $symbol,
                        'interval' => (string) config('exchange.default_timeframe', '5m'),
                        'direction' => $dir,
                        'signal_type' => 'EXTERNAL',
                        'status' => $closingOrder ? Position::STATUS_CLOSED : Position::STATUS_OPEN,
                        'entry_price' => $entryPrice > 0.0 ? $entryPrice : 0.0,
                        'stop_price' => 0.0,
                        'target1' => 0.0,
                        'target2' => 0.0,
                        'quantity' => $qty,
                        'size' => 1.0,
                        'leverage' => $leverage,
                        'exit_price' => $exitPrice,
                        'realized_pnl' => $closingOrder ? $realizedPnl : null,
                        'commission' => $totalCommission,
                        'exit_type' => $exitType,
                        'exit_reason' => $exitReason,
                        'entry_order_id' => $openingOrder ? (string) $openingOrder['orderId'] : null,
                        'exit_order_id' => $closingOrder ? (string) $closingOrder['orderId'] : null,
                        'external_id' => $posId,
                        'opened_at' => $openedAt,
                        'closed_at' => $closedAt,
                        'synced_at' => now(),
                    ]);
                }
                $result->imported++;
                $result->messages[] = sprintf(
                    'Imported %s position %s %s: entry=%.4f, exit=%s, pnl=%.4f, fee=%.4f (net=%.4f)',
                    $closingOrder ? 'closed' : 'open',
                    $symbol,
                    $dir,
                    $entryPrice,
                    $exitPrice ? sprintf('%.4f', $exitPrice) : '—',
                    $realizedPnl,
                    $totalCommission,
                    $realizedPnl - $totalCommission
                );
            }
        }
    }

    /**
     * Resolves exact exit price, realized PnL, and fees for a position from BingX trade history.
     *
     * @return array{
     *     exit_price: float|null,
     *     realized_pnl: float|null,
     *     commission: float,
     *     funding_fee: float,
     *     exit_type: string|null,
     *     exit_reason: string|null,
     *     exit_order_id: string|null,
     *     closed_at: Carbon
     * }
     */
    private function resolveClosedPositionData(Position $pos, int $lookbackDays): array
    {
        $symbol = $pos->symbol;
        $isLong = $pos->direction === Direction::Long->value;
        $expectedClosingSide = $isLong ? 'SELL' : 'BUY';
        $startTime = $pos->opened_at
            ? (int) $pos->opened_at->copy()->subMinutes(10)->getTimestampMs()
            : (int) now()->subDays($lookbackDays)->getTimestampMs();

        // 1. Fetch orders from BingX
        $orders = $this->fetchOrders($symbol, $startTime);

        // First pass: find positionID from entry_order_id or external_id if present
        $matchedPositionId = $pos->external_id;
        if (! $matchedPositionId && ! empty($pos->entry_order_id)) {
            foreach ($orders as $order) {
                if ((string) ($order['orderId'] ?? '') === (string) $pos->entry_order_id) {
                    $matchedPositionId = ! empty($order['positionID']) ? (string) $order['positionID'] : null;
                    break;
                }
            }
        }

        $closingOrder = null;
        $totalCommission = 0.0;
        $realizedPnlFromOrders = null;

        foreach ($orders as $order) {
            $status = (string) ($order['status'] ?? '');
            if ($status !== 'FILLED') {
                continue;
            }

            $orderPosId = ! empty($order['positionID']) ? (string) $order['positionID'] : null;
            $orderSide = (string) ($order['side'] ?? '');
            $orderPosSide = (string) ($order['positionSide'] ?? '');
            $orderFee = abs((float) ($order['commission'] ?? 0.0));
            $orderProfit = (float) ($order['profit'] ?? 0.0);
            $orderTime = (int) ($order['updateTime'] ?? $order['time'] ?? 0);

            // Check if this order belongs to our position
            $isSamePosition = false;
            if ($matchedPositionId !== null && $orderPosId !== null && $orderPosId === $matchedPositionId) {
                $isSamePosition = true;
            } elseif ($orderTime >= $startTime) {
                // If positionID didn't match, check by direction and symbol
                if (empty($orderPosSide) || $orderPosSide === $pos->direction) {
                    $isSamePosition = true;
                }
            }

            if ($isSamePosition) {
                $totalCommission += $orderFee;

                $isClosing = ($orderSide === $expectedClosingSide)
                    && (! empty($order['reduceOnly']) || $orderProfit != 0.0 || in_array($order['type'] ?? '', ['TAKE_PROFIT_MARKET', 'STOP_MARKET', 'STOP', 'TAKE_PROFIT'], true));

                if ($isClosing && ($closingOrder === null || $orderTime > (int) ($closingOrder['updateTime'] ?? 0))) {
                    $closingOrder = $order;
                    $realizedPnlFromOrders = $orderProfit;
                    if ($orderPosId !== null && $matchedPositionId === null) {
                        $matchedPositionId = $orderPosId;
                    }
                }
            }
        }

        // 2. Fetch income records (realized PnL, commissions, and funding fees)
        $incomeRecords = $this->fetchIncome($symbol, $startTime);
        $realizedPnlFromIncome = null;
        $feesFromIncome = 0.0;
        $fundingFees = 0.0;

        foreach ($incomeRecords as $item) {
            $type = (string) ($item['incomeType'] ?? '');
            $amount = (float) ($item['income'] ?? 0.0);

            if ($type === 'REALIZED_PNL') {
                $realizedPnlFromIncome = ($realizedPnlFromIncome ?? 0.0) + $amount;
            } elseif ($type === 'TRADING_FEE') {
                $feesFromIncome += abs($amount);
            } elseif ($type === 'FUNDING_FEE') {
                $fundingFees += $amount;
            }
        }

        $finalPnl = $realizedPnlFromOrders ?? $realizedPnlFromIncome ?? $pos->realized_pnl;
        $finalFees = max($totalCommission, $feesFromIncome);

        $exitPrice = null;
        $exitType = null;
        $exitReason = null;
        $exitOrderId = null;
        $closedAt = now();

        if ($closingOrder !== null) {
            $exitPrice = (float) ($closingOrder['avgPrice'] ?? $closingOrder['price'] ?? 0.0);
            $rawType = (string) ($closingOrder['type'] ?? 'MARKET');
            $exitOrderId = (string) ($closingOrder['orderId'] ?? '');

            if (in_array($rawType, ['TAKE_PROFIT_MARKET', 'TAKE_PROFIT'], true)) {
                $exitType = ExitType::Target1->value;
                $exitReason = 'take_profit_hit';
            } elseif (in_array($rawType, ['STOP_MARKET', 'STOP'], true)) {
                $exitType = ExitType::StopLoss->value;
                $exitReason = 'stop_loss_hit';
            } else {
                $exitType = 'MARKET';
                $exitReason = 'external_close';
            }

            if (! empty($closingOrder['updateTime'])) {
                $closedAt = Carbon::createFromTimestampMs((int) $closingOrder['updateTime']);
            }
        }

        return [
            'exit_price' => $exitPrice > 0.0 ? $exitPrice : $pos->exit_price,
            'realized_pnl' => $finalPnl,
            'commission' => $finalFees,
            'funding_fee' => $fundingFees,
            'exit_type' => $exitType ?? $pos->exit_type ?? 'MARKET',
            'exit_reason' => $exitReason ?? $pos->exit_reason ?? 'exchange_closed',
            'exit_order_id' => $exitOrderId ?: $pos->exit_order_id,
            'closed_at' => $closedAt,
        ];
    }

    /**
     * Fetch active open positions from BingX.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchOpenPositions(?string $symbol = null): array
    {
        $params = [];
        if ($symbol !== null) {
            $params['symbol'] = $symbol;
        }

        $response = $this->get('/openApi/swap/v2/user/positions', $params);
        if (($response['code'] ?? -1) !== 0) {
            return [];
        }

        $items = (array) ($response['data'] ?? []);
        $open = [];

        foreach ($items as $item) {
            $amt = abs((float) ($item['positionAmt'] ?? 0.0));
            if ($amt > 0.0) {
                $open[] = $item;
            }
        }

        return $open;
    }

    /**
     * Fetch income history (REALIZED_PNL, TRADING_FEE, FUNDING_FEE) from BingX.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchIncome(string $symbol, ?int $startTime = null, ?string $incomeType = null, int $limit = 100): array
    {
        $params = ['symbol' => $symbol, 'limit' => $limit];
        if ($startTime !== null) {
            $params['startTime'] = $startTime;
        }
        if ($incomeType !== null) {
            $params['incomeType'] = $incomeType;
        }

        $response = $this->get('/openApi/swap/v2/user/income', $params);
        if (($response['code'] ?? -1) !== 0) {
            return [];
        }

        return (array) ($response['data'] ?? []);
    }

    /**
     * Fetch all orders for a symbol.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchOrders(string $symbol, ?int $startTime = null, int $limit = 100): array
    {
        $params = ['symbol' => $symbol, 'limit' => $limit];
        if ($startTime !== null) {
            $params['startTime'] = $startTime;
        }

        $response = $this->get('/openApi/swap/v2/trade/allOrders', $params);
        if (($response['code'] ?? -1) !== 0) {
            return [];
        }

        return (array) ($response['data']['orders'] ?? []);
    }

    /**
     * Fetch all trade fills for a symbol.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchFills(string $symbol, ?int $startTime = null, int $limit = 100): array
    {
        $params = ['symbol' => $symbol, 'limit' => $limit];
        if ($startTime !== null) {
            $params['startTime'] = $startTime;
        }

        $response = $this->get('/openApi/swap/v2/trade/allFills', $params);
        if (($response['code'] ?? -1) !== 0) {
            return [];
        }

        return (array) ($response['data']['fills'] ?? $response['data'] ?? []);
    }

    /**
     * Send signed GET request to BingX private API.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function get(string $path, array $params = []): array
    {
        $key = (string) ($this->config['api_key'] ?? '');
        $secret = (string) ($this->config['api_secret'] ?? '');
        if ($key === '' || $secret === '') {
            return ['code' => -1, 'msg' => 'API credentials missing'];
        }

        $params['timestamp'] = (int) (microtime(true) * 1000);
        ksort($params);
        $query = http_build_query($params);
        $signature = hash_hmac('sha256', $query, $secret);

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl())
                ->timeout((int) ($this->config['timeout'] ?? 15))
                ->withHeaders(['X-BX-APIKEY' => $key])
                ->get($path, $params + ['signature' => $signature]);

            return (array) $response->json();
        } catch (Throwable $e) {
            Log::warning('BingX API GET error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    private function isDemo(): bool
    {
        return (bool) ($this->config['demo'] ?? false);
    }

    private function baseUrl(): string
    {
        $url = $this->isDemo()
            ? ($this->config['base_url_demo'] ?? 'https://open-api-vst.bingx.com')
            : ($this->config['base_url'] ?? 'https://open-api.bingx.com');

        return rtrim((string) $url, '/');
    }
}
