<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Position;
use App\Market\Repositories\CandleRepository;
use App\Trading\Charting\ChartRenderer;
use Illuminate\Console\Command;

/**
 * (Re)render the chart for a logged position.
 *
 *   php artisan agent:chart 12
 *
 * Writes the spec JSON regardless, and renders the PNG when Python/matplotlib
 * are available. In the dev sandbox the container has no Python — run the
 * command there to emit the spec, then render it on the host:
 *
 *   python3 scripts/render_position.py storage/app/charts/specs/position_12.json
 */
class RenderPositionChart extends Command
{
    protected $signature = 'agent:chart {position : Position id}';

    protected $description = 'Render the candles/level/EMA/entry-exit chart for a position';

    public function handle(CandleRepository $candlesRepo): int
    {
        $position = Position::find((int) $this->argument('position'));
        if ($position === null) {
            $this->error('Position not found.');

            return self::FAILURE;
        }

        $candles = $candlesRepo->recent($position->symbol, $position->interval);
        if ($candles === []) {
            $this->error("No candles for {$position->symbol} {$position->interval}.");

            return self::FAILURE;
        }

        // Force-enable rendering for this explicit, on-demand invocation.
        $renderer = new ChartRenderer(['enabled' => true] + (array) config('trading.chart'));
        $path = $renderer->render($position, $candles);

        if ($path === null) {
            $this->warn("Spec written to storage/app/charts/specs/position_{$position->id}.json — render it on a host with matplotlib.");

            return self::SUCCESS;
        }

        $position->update(['chart_path' => $path]);
        $this->info("Chart saved: storage/app/{$path}");

        return self::SUCCESS;
    }
}
