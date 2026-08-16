<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Market\Contracts\ExchangeInterface;
use App\Market\Contracts\MarketAnalyzerInterface;
use App\Market\Repositories\CandleRepository;
use App\Market\DTO\Candle;
use App\Market\DTO\Level;
use App\Market\DTO\Pattern;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;

/**
 * Candlestick + volume dashboard (lightweight-charts) with toggleable overlays
 * for support/resistance levels, detected figures and ATR.
 *
 * Market data and analysis are fetched from the browser via the Livewire
 * method {@see self::marketData()} (called through `$wire`).
 */
class MarketDashboard extends Page
{
    protected string $view = 'filament.pages.market-dashboard';

    protected Width | string | null $maxContentWidth = Width::Full;

    public ?string $symbol = null;
    public ?string $interval = null;

    public static function getNavigationLabel(): string
    {
        return 'Маркет';
    }

    public function getTitle(): string
    {
        return 'Аналитика рынка';
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return 'heroicon-o-chart-bar';
    }

    public function mount(): void
    {
        $this->symbol ??= (string) config('exchange.default_pair');
        $this->interval ??= (string) config('exchange.default_timeframe');
    }

    /** @return array<int, string> */
    public function getPairs(): array
    {
        return (array) config('exchange.pairs');
    }

    /** @return array<int, string> */
    public function getTimeframes(): array
    {
        return array_keys((array) config('exchange.timeframes'));
    }

    /**
     * Full payload for the chart: candles (for the candlestick + volume series)
     * plus the computed overlays. Invoked from JS via `$wire.marketData(...)`.
     *
     * @return array<string, mixed>
     */
    public function marketData(string $symbol, string $interval): array
    {
        $symbol = $this->sanitiseSymbol($symbol);
        $interval = $this->sanitiseInterval($interval);

        $repository = app(CandleRepository::class);
        $analyzer = app(MarketAnalyzerInterface::class);
        $exchange = app(ExchangeInterface::class);

        $candles = $repository->recent($symbol, $interval, (int) config('exchange.klines_limit', 500));
        $htfInterval = $this->higherTimeframe($interval);
        $atr = $analyzer->atr($symbol, $interval);

        $lastClose = count($candles) > 0 ? end($candles)->close : null;
        $atrPercent = ($atr > 0 && $lastClose > 0) ? round(($atr / $lastClose) * 100, 3) : 0.0;

        return [
            'symbol' => $symbol,
            'interval' => $interval,
            'exchange' => $exchange->name(),
            'candles' => array_map(static fn (Candle $c) => $c->toChartArray(), $candles),
            'atr' => $atr,
            'atr_percent' => $atrPercent,
            'levels' => array_map(static fn (Level $l) => $l->toArray(), $analyzer->levels($symbol, $interval)),
            'htf_interval' => $htfInterval,
            'htf_levels' => $htfInterval
                ? array_map(static fn (Level $l) => $l->toArray(), $analyzer->levels($symbol, $htfInterval))
                : [],
            'trend' => $analyzer->trend($symbol, $interval)->toArray(),
            'patterns' => array_map(static fn (Pattern $p) => $p->toArray(), $analyzer->patterns($symbol, $interval)),
            'ticker' => $this->safeTicker($exchange, $symbol),
        ];
    }

    /**
     * Ticker requires a live exchange call; never let it break the dashboard.
     *
     * @return array<string, mixed>|null
     */
    private function safeTicker(ExchangeInterface $exchange, string $symbol): ?array
    {
        try {
            return $exchange->ticker($symbol);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Returns the next higher timeframe key, or null if already at the highest. */
    private function higherTimeframe(string $interval): ?string
    {
        $timeframes = array_keys((array) config('exchange.timeframes'));
        $index = array_search($interval, $timeframes, true);

        return ($index !== false && $index < count($timeframes) - 1)
            ? $timeframes[$index + 1]
            : null;
    }

    private function sanitiseSymbol(string $symbol): string
    {
        $pairs = (array) config('exchange.pairs');

        return in_array($symbol, $pairs, true) ? $symbol : (string) config('exchange.default_pair');
    }

    private function sanitiseInterval(string $interval): string
    {
        $timeframes = array_keys((array) config('exchange.timeframes'));

        return in_array($interval, $timeframes, true) ? $interval : (string) config('exchange.default_timeframe');
    }
}
