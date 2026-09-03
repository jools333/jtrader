<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RenderOutcomeChartJob;
use App\Market\Repositories\CandleRepository;
use App\Models\StrategyEvaluation;
use App\Trading\Charting\ChartRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RenderStrategyOutcomes extends Command
{
    protected $signature = 'strategy:render-outcomes 
        {--limit= : Maximum number of evaluations to process}
        {--sync : Force synchronous rendering without queue}';

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
        $limit = (int) ($this->option('limit') ?: config('trading.outcome_chart.limit', 5));
        $useQueue = ! $this->option('sync') && (bool) config('trading.outcome_chart.queue', true);

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

        $processedCount = 0;

        foreach ($evaluations as $eval) {
            /** @var StrategyEvaluation $eval */
            if ($useQueue && Cache::has("outcome_queued_{$eval->id}")) {
                continue;
            }

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

            if ($useQueue) {
                Cache::put("outcome_queued_{$eval->id}", true, now()->addMinutes(15));
                RenderOutcomeChartJob::dispatch($eval->id, $candles, (int) $eval->candle_open_time);
                $processedCount++;
            } else {
                $path = $chartRenderer->renderOutcome($eval, $candles, (int) $eval->candle_open_time);
                if ($path !== null) {
                    $eval->update(['outcome_chart_path' => $path]);
                    $processedCount++;
                }
            }
        }

        if ($processedCount > 0) {
            $verb = $useQueue ? 'Queued' : 'Rendered';
            $this->info("{$verb} outcome charts for {$processedCount} strategy evaluations.");
        }

        return self::SUCCESS;
    }
}
