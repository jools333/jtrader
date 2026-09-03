<?php

declare(strict_types=1);

namespace App\Providers;

use App\Trading\Agent\TradingAgent;
use App\Trading\Charting\ChartRenderer;
use App\Trading\Contracts\TradeExecutorInterface;
use App\Trading\Contracts\TradingAgentInterface;
use App\Trading\Execution\BingX\BingXTradeExecutor;
use App\Trading\Execution\PaperTradeExecutor;
use App\Trading\Execution\PositionManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

use App\Trading\Contracts\StrategyLoggerInterface;
use App\Trading\Services\DatabaseStrategyLogger;

/**
 * Wires the trading agent and its order-routing executor.
 *
 * The executor is chosen by config('trading.executor'); add a driver to the
 * match below to route to another venue — the agent and persistence layers are
 * unchanged. Mirrors {@see ExchangeServiceProvider} on the trading side.
 */
class TradingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StrategyLoggerInterface::class, fn (Application $app) => new DatabaseStrategyLogger(
            minScoreThreshold: (float) config('trading.min_eval_log_score', 50.0),
            chart: $app->make(ChartRenderer::class),
        ));

        $this->app->singleton(TradingAgentInterface::class, fn (Application $app) => new TradingAgent(
            config: (array) config('trading.agent'),
            logger: $app->make(StrategyLoggerInterface::class),
        ));

        $this->app->singleton(TradeExecutorInterface::class, function (Application $app): TradeExecutorInterface {
            $driver = (string) config('trading.executor', 'paper');

            return match ($driver) {
                'paper' => new PaperTradeExecutor(
                    log: $app->make(LoggerInterface::class),
                    balance: (float) config('trading.paper_balance', 1_000.0),
                ),
                'bingx' => new BingXTradeExecutor(
                    http: $app->make(HttpFactory::class),
                    config: (array) config('exchange.drivers.bingx'),
                ),
                default => throw new InvalidArgumentException("Unsupported trade executor [{$driver}]."),
            };
        });

        $this->app->singleton(ChartRenderer::class, fn (Application $app) => new ChartRenderer(
            (array) config('trading.chart'),
        ));

        $this->app->singleton(PositionManager::class, fn (Application $app) => new PositionManager(
            agent: $app->make(TradingAgentInterface::class),
            executor: $app->make(TradeExecutorInterface::class),
            config: (array) config('trading'),
            chart: $app->make(ChartRenderer::class),
        ));

        $this->app->singleton(\App\Trading\Services\BtcImpulseDetector::class, fn (Application $app) => new \App\Trading\Services\BtcImpulseDetector(
            candlesRepo: $app->make(\App\Market\Repositories\CandleRepository::class),
            analyzer: $app->make(\App\Market\Contracts\MarketAnalyzerInterface::class),
            manager: $app->make(PositionManager::class),
            config: (array) config('trading.agent'),
        ));
    }
}
