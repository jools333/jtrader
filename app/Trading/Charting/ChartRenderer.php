<?php

declare(strict_types=1);

namespace App\Trading\Charting;

use App\Market\Analysis\Support\SeriesMath;
use App\Market\DTO\Candle;
use App\Models\Position;
use App\Trading\Analysis\CandleSignals;
use App\Trading\Enums\Direction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Renders a per-position chart (candles + level + EMA8/EMA21 + entry/exit
 * markers + volume) so the reasoning behind each trade is visible at a glance.
 *
 * Builds a JSON spec and hands it to {@see scripts/render_position.py}
 * (matplotlib). The spec is always written to bind-mounted storage; rendering
 * is best-effort — if the Python/matplotlib toolchain isn't reachable (as in
 * the container, which has no Python), the trade is unaffected and the spec can
 * be rendered out-of-band on the host:
 *
 *     python3 scripts/render_position.py storage/app/charts/specs/<file>.json
 */
final class ChartRenderer
{
    /** @param array<string, mixed> $config the `trading.chart` block */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Render (or re-render) the chart for a position and return the path
     * relative to the `local` disk (storable in `positions.chart_path`), or
     * null if rendering is disabled or failed.
     *
     * @param array<int, Candle> $candles oldest -> newest (window to plot)
     */
    public function render(Position $position, array $candles): ?string
    {
        if (! ($this->config['enabled'] ?? false)) {
            return null;
        }

        $window = array_slice(array_values($candles), -(int) ($this->config['window'] ?? 60));
        if (count($window) < 2) {
            return null;
        }

        $specPath = storage_path("app/charts/specs/position_{$position->id}.json");
        $relative = "charts/position_{$position->id}.png";
        $outPath = storage_path("app/{$relative}");

        File::ensureDirectoryExists(dirname($specPath));
        File::ensureDirectoryExists(dirname($outPath));
        File::put($specPath, json_encode($this->buildSpec($position, $window, $outPath), JSON_THROW_ON_ERROR));

        return $this->invoke($specPath, $relative, $outPath);
    }

    /**
     * Assemble the JSON spec consumed by the Python renderer.
     *
     * @param array<int, Candle> $window
     * @return array<string, mixed>
     */
    private function buildSpec(Position $position, array $window, string $outPath): array
    {
        $closes = array_map(static fn (Candle $c) => $c->close, $window);
        $atr = SeriesMath::atrSma($window, 14);

        $candles = array_map(function (Candle $c) use ($atr): array {
            $impulse = null;
            if (CandleSignals::isBullishImpulse($c, $atr)) {
                $impulse = 'bull';
            } elseif (CandleSignals::isBearishImpulse($c, $atr)) {
                $impulse = 'bear';
            }

            return [
                'o' => $c->open, 'h' => $c->high, 'l' => $c->low, 'c' => $c->close, 'v' => $c->volume,
                'compression' => CandleSignals::isCompression($c, $atr),
                'impulse' => $impulse,
            ];
        }, $window);

        $long = $position->direction() === Direction::Long;
        $dir = $long ? 'LONG' : 'SHORT';

        $spec = [
            'title' => "{$position->symbol} {$position->interval} — {$position->signal_type} {$dir}",
            'candles' => $candles,
            'level' => round($this->level($position), 8),
            'stop' => round($position->stop_price, 8),
            'target1' => round($position->target1, 8),
            'target2' => round($position->target2, 8),
            'ema_fast' => array_map(static fn (float $v) => round($v, 8), SeriesMath::ema($closes, 8)),
            'ema_fast_period' => 8,
            'ema_slow' => array_map(static fn (float $v) => round($v, 8), SeriesMath::ema($closes, 21)),
            'ema_slow_period' => 21,
            'atr' => round($atr, 8),
            'entry' => null,
            'exit' => null,
            'out' => $outPath,
        ];

        // Entry marker on the candle that triggered the position.
        $entryIndex = $this->indexForOpenTime($window, (int) ($position->entry_context['entry_open_time'] ?? 0));
        if ($entryIndex !== null) {
            $spec['entry'] = [
                'index' => $entryIndex,
                'price' => round($position->entry_price, 8),
                'direction' => $long ? 'up' : 'down',
                'label' => 'ВХОД ('.($long ? 'лонг' : 'шорт').")\n{$position->signal_type}",
            ];
        }

        // Exit marker once the position is closed.
        if ($position->status === Position::STATUS_CLOSED && $position->exit_price !== null) {
            $spec['exit'] = [
                'index' => count($window) - 1,
                'price' => round($position->exit_price, 8),
                'direction' => $long ? 'down' : 'up',
                'label' => 'ВЫХОД'."\n".($position->exit_type ?? ''),
            ];
        }

        return $spec;
    }

    /** Best-effort level: the entry context records nothing extra, so use the plan's stop side. */
    private function level(Position $position): float
    {
        // The level is implicit in the entry context's signal payload if present.
        return (float) ($position->entry_context['level']
            ?? $position->entry_context['signal']['entry']
            ?? $position->entry_price);
    }

    /**
     * Index of the candle whose openTime matches, or null.
     *
     * @param array<int, Candle> $window
     */
    private function indexForOpenTime(array $window, int $openTime): ?int
    {
        if ($openTime === 0) {
            return count($window) - 1; // fall back to the last bar
        }
        foreach ($window as $i => $c) {
            if ($c->openTime === $openTime) {
                return $i;
            }
        }

        return null;
    }

    /** Run the Python renderer; return the relative path on success. */
    private function invoke(string $specPath, string $relative, string $outPath): ?string
    {
        $python = (string) ($this->config['python_bin'] ?? 'python3');
        $script = (string) ($this->config['script'] ?? base_path('scripts/render_position.py'));

        try {
            $result = Process::timeout((int) ($this->config['timeout'] ?? 60))
                ->run([$python, $script, $specPath]);

            if ($result->successful() && File::exists($outPath)) {
                return $relative;
            }

            Log::warning('[chart] render failed; spec kept for out-of-band rendering', [
                'spec' => $specPath,
                'error' => $result->errorOutput() ?: $result->output(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[chart] renderer unavailable; spec kept for out-of-band rendering', [
                'spec' => $specPath,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
