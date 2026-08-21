<?php

declare(strict_types=1);

namespace App\Trading\Services;

use App\Models\StrategyEvaluation;
use App\Trading\Contracts\StrategyLoggerInterface;
use App\Trading\DTO\StrategyEvaluationResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists strategy evaluations (completed or partial >= threshold) to database.
 */
final class DatabaseStrategyLogger implements StrategyLoggerInterface
{
    public function __construct(private readonly float $minScoreThreshold = 50.0)
    {
    }

    public function log(StrategyEvaluationResult $result): void
    {
        // Only log evaluations reaching at least the minimum score threshold
        if ($result->score < $this->minScoreThreshold) {
            return;
        }

        try {
            StrategyEvaluation::create([
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
        } catch (Throwable $e) {
            Log::warning('Failed to log strategy evaluation', [
                'strategy' => $result->strategy,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
