<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Market\DTO\Candle;
use App\Models\StrategyEvaluation;
use App\Trading\Charting\ChartRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenderOutcomeChartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $evaluationId
     * @param array<int, Candle> $candles
     * @param int $targetOpenTime
     */
    public function __construct(
        public readonly int $evaluationId,
        public readonly array $candles,
        public readonly int $targetOpenTime,
    ) {
        $this->onQueue('charts');
    }

    public function handle(ChartRenderer $chartRenderer): void
    {
        try {
            $eval = StrategyEvaluation::find($this->evaluationId);
            if ($eval !== null && $eval->outcome_chart_path === null) {
                $path = $chartRenderer->renderOutcome($eval, $this->candles, $this->targetOpenTime);
                if ($path !== null) {
                    $eval->update(['outcome_chart_path' => $path]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('RenderOutcomeChartJob failed', [
                'evaluation_id' => $this->evaluationId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            Cache::forget("outcome_queued_{$this->evaluationId}");
        }
    }
}
