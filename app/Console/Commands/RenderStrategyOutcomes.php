<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Market\Repositories\CandleRepository;
use App\Models\StrategyEvaluation;
use App\Trading\Charting\ChartRenderer;
use Illuminate\Console\Command;

class RenderStrategyOutcomes extends Command
{
    protected $signature = 'strategy:render-outcomes {--limit=50 : Maximum number of evaluations to process}';

    protected $description = 'Render follow-up outcome charts (+30 candles) for strategy evaluations';

    public function handle(CandleRepository $candleRepo, ChartRenderer $chartRenderer): int
    {
        $enabled = (bool) config('trading.outcome_chart.enabled', true);
        if (! $enabled) {
            $this->info('Outcome chart rendering is disabled in config.');

            return self::SUCCESS;
        }

        $afterCount = (int) config('trading.outcome_chart.after_candles', 30);
        $beforeCount = (int) config('trading.outcome_chart.before_candles', 40);
        $limit = (int) $this->option('limit');

        $evaluations = StrategyEvaluation::query()
            ->whereNull('outcome_chart_path')
            ->whereNotNull('symbol')
            ->whereNotNull('interval')
            ->whereNotNull('candle_open_time')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        if ($evaluations->isEmpty()) {
            return self::SUCCESS;
        }

        $renderedCount = 0;

        foreach ($evaluations as $eval) {
            /** @var StrategyEvaluation $eval */
            $candles = $candleRepo->windowAround(
                symbol: (string) $eval->symbol,
                interval: (string) $eval->interval,
                targetOpenTime: (int) $eval->candle_open_time,
                beforeCount: $beforeCount,
                afterCount: $afterCount,
            );

            if ($candles === null) {
                // Not enough candles after targetOpenTime yet
                continue;
            }

            $path = $chartRenderer->renderOutcome($eval, $candles, (int) $eval->candle_open_time);
            if ($path !== null) {
                $eval->update(['outcome_chart_path' => $path]);
                $renderedCount++;
            }
        }

        if ($renderedCount > 0) {
            $this->info("Rendered outcome charts for {$renderedCount} strategy evaluations.");
        }

        return self::SUCCESS;
    }
}
