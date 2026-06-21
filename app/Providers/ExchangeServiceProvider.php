<?php

declare(strict_types=1);

namespace App\Providers;

use App\Market\Analysis\MarketAnalyzer;
use App\Market\Analysis\Support\PatternDetector;
use App\Market\Contracts\ExchangeInterface;
use App\Market\Contracts\MarketAnalyzerInterface;
use App\Market\Exchange\BingX\BingXExchange;
use App\Market\Repositories\CandleRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wires the exchange abstraction and the analysis layer.
 *
 * The active exchange is chosen by config('exchange.default'); add a driver to
 * the match below to support another exchange — nothing else needs to change.
 */
class ExchangeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExchangeInterface::class, function (Application $app): ExchangeInterface {
            $driver = (string) config('exchange.default');

            return match ($driver) {
                'bingx' => new BingXExchange(
                    http: $app->make(HttpFactory::class),
                    config: (array) config('exchange.drivers.bingx'),
                    pairs: (array) config('exchange.pairs'),
                    timeframes: (array) config('exchange.timeframes'),
                ),
                default => throw new InvalidArgumentException("Unsupported exchange driver [{$driver}]."),
            };
        });

        $this->app->singleton(CandleRepository::class, fn (Application $app) => new CandleRepository(
            $app->make(ExchangeInterface::class),
        ));

        $this->app->singleton(PatternDetector::class);

        $this->app->singleton(MarketAnalyzerInterface::class, fn (Application $app) => new MarketAnalyzer(
            $app->make(CandleRepository::class),
            $app->make(PatternDetector::class),
        ));
    }
}
