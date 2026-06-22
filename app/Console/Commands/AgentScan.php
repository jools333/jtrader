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
        {--interval= : Limit to a single timeframe}';

    protected $description = 'Evaluate the trading agent and act on entry/exit signals';

    public function handle(
        CandleRepository $candlesRepo,
        MarketAnalyzerInterface $analyzer,
        PositionManager $manager,
    ): int {
        $symbols = $this->option('symbol') ? [$this->option('symbol')] : (array) config('exchange.pairs');
        $intervals = $this->option('interval') ? [$this->option('interval')] : array_keys((array) config('exchange.timeframes'));

        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                try {
                    $this->scan($candlesRepo, $analyzer, $manager, $symbol, $interval);
                } catch (Throwable $e) {
                    $this->line(sprintf('  <error>✗</error> %s %s — %s', $symbol, $interval, $e->getMessage()));
                }
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

        $atr = $analyzer->atr($symbol, $interval);
        $level = $this->nearestLevel($analyzer->levels($symbol, $interval), $candles);
        if ($level === null) {
            $this->line(sprintf('  <comment>–</comment> %s %s — no levels', $symbol, $interval));

            return;
        }

        $result = $manager->process($symbol, $interval, $candles, $level, $atr);

        $entry = $result->entrySignal ? $result->entrySignal->type->value.' '.$result->entrySignal->direction->value : '—';
        $exit = $result->exitSignal ? $result->exitSignal->type->value : '—';
        $this->line(sprintf(
            '  <info>✓</info> %s %s @ %.4f — entry: %s | exit: %s',
            $symbol,
            $interval,
            $level,
            $entry,
            $exit,
        ));
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
