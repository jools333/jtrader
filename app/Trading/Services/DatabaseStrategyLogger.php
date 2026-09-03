<?php

declare(strict_types=1);

namespace App\Trading\Services;

use App\Jobs\RenderEvaluationChartJob;
use App\Models\StrategyEvaluation;
use App\Trading\Charting\ChartRenderer;
use App\Trading\Contracts\StrategyLoggerInterface;
use App\Trading\DTO\StrategyEvaluationResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists strategy evaluations (completed or partial >= threshold) to database.
 */
final class DatabaseStrategyLogger implements StrategyLoggerInterface
{
    public function __construct(
        private readonly float $minScoreThreshold = 50.0,
        private readonly ?ChartRenderer $chart = null,
    ) {
    }

    public function log(StrategyEvaluationResult $result): void
    {
        // Only log evaluations reaching at least the minimum score threshold
        if ($result->score < $this->minScoreThreshold) {
            return;
        }

        try {
            $evaluation = StrategyEvaluation::create([
                'symbol' => $result->symbol,
                'interval' => $result->interval,
                'strategy' => $result->strategy,
                'direction' => $result->direction->value,
                'status' => $result->isFullSignal ? StrategyEvaluation::STATUS_COMPLETED : StrategyEvaluation::STATUS_PARTIAL,
                'score' => $result->score,
                'passed_count' => $result->passedCount,
                'total_count' => $result->totalCount,
                'level' => $result->level,
                'atr' => $result->atr,
                'current_price' => $result->currentPrice,
                'entry_price' => $result->entrySignal?->entryPrice,
                'stop_price' => $result->entrySignal?->stop,
                'target1' => $result->entrySignal?->target1,
                'target2' => $result->entrySignal?->target2,
                'rr_ratio' => $result->entrySignal?->rrRatio,
                'missing_criteria' => $result->missingCriteria,
                'criteria_breakdown' => $result->criteriaToArray(),
                'indicators' => $result->indicators,
                'candle_open_time' => $result->candleOpenTime,
                'evaluated_at' => now(),
            ]);

            // If candles are available and chart rendering is enabled, render and attach chart
            if ($this->chart !== null && ! empty($result->candles) && (bool) config('trading.chart.enabled', false)) {
                if ((bool) config('trading.chart.queue', true)) {
                    RenderEvaluationChartJob::dispatch($evaluation->id, $result->candles);
                } else {
                    $path = $this->chart->renderEvaluation($evaluation, $result->candles);
                    if ($path !== null) {
                        $evaluation->update(['chart_path' => $path]);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed to log strategy evaluation', [
                'strategy' => $result->strategy,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
