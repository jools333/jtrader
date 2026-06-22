<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Market\Contracts\MarketAnalyzerInterface;
use App\Market\DTO\Candle;
use App\Market\DTO\Level;
use App\Market\Repositories\CandleRepository;
use App\Trading\Execution\PositionManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run the trading agent over configured pairs/timeframes against the nearest
 * key level, acting on any signal and logging positions to the database.
 *
 *   php artisan agent:scan                    # all configured pairs/timeframes
 *   php artisan agent:scan --symbol=BTC-USDT --interval=5m
 */
class AgentScan extends Command
{
    protected $signature = 'agent:scan
        {--symbol= : Limit to a single symbol}
        {--interval=5m : Entry timeframe (levels are built on the next higher timeframe)}';

    protected $description = 'Evaluate the trading agent and act on entry/exit signals';

    public function handle(
        CandleRepository $candlesRepo,
        MarketAnalyzerInterface $analyzer,
        PositionManager $manager,
    ): int {
        $symbols = $this->option('symbol') ? [$this->option('symbol')] : (array) config('exchange.pairs');
        $interval = (string) $this->option('interval');

        foreach ($symbols as $symbol) {
            try {
                $this->scan($candlesRepo, $analyzer, $manager, $symbol, $interval);
            } catch (Throwable $e) {
                $this->line(sprintf('  <error>✗</error> %s %s — %s', $symbol, $interval, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    private function scan(
        CandleRepository $candlesRepo,
        MarketAnalyzerInterface $analyzer,
        PositionManager $manager,
        string $symbol,
        string $interval,
    ): void {
        $candles = $candlesRepo->recent($symbol, $interval);
        if (count($candles) < 50) {
            $this->line(sprintf('  <comment>–</comment> %s %s — not enough candles (%d)', $symbol, $interval, count($candles)));

            return;
        }

        $levelInterval = $this->higherTimeframe($interval);
        $atr = $analyzer->atr($symbol, $interval);
        $level = $this->nearestLevel($analyzer->levels($symbol, $levelInterval), $candles);
        if ($level === null) {
            $this->line(sprintf('  <comment>–</comment> %s %s — no levels on %s', $symbol, $interval, $levelInterval));

            return;
        }

        $result = $manager->process($symbol, $interval, $candles, $level, $atr);

        $entry = $result->entrySignal ? $result->entrySignal->type->value.' '.$result->entrySignal->direction->value : '—';
        $exit = $result->exitSignal ? $result->exitSignal->type->value : '—';
        $this->line(sprintf(
            '  <info>✓</info> %s %s (levels: %s) @ %.4f — entry: %s | exit: %s',
            $symbol,
            $interval,
            $levelInterval,
            $level,
            $entry,
            $exit,
        ));
    }

    /**
     * Returns the next higher timeframe from config('exchange.timeframes').
     * Falls back to the same interval if already at the top.
     */
    private function higherTimeframe(string $interval): string
    {
        $timeframes = array_keys((array) config('exchange.timeframes'));
        $idx = array_search($interval, $timeframes, true);
        if ($idx === false || $idx >= count($timeframes) - 1) {
            return $interval;
        }

        return $timeframes[$idx + 1];
    }

    /**
     * The configured level closest to the current price.
     *
     * @param array<int, Level> $levels
     * @param array<int, Candle> $candles
     */
    private function nearestLevel(array $levels, array $candles): ?float
    {
        if ($levels === []) {
            return null;
        }

        $price = $candles[count($candles) - 1]->close;
        usort($levels, static fn (Level $a, Level $b) => abs($a->price - $price) <=> abs($b->price - $price));

        return $levels[0]->price;
    }
}
