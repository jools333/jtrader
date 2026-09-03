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
use Illuminate\Support\Facades\Log;
use Throwable;

class RenderEvaluationChartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $evaluationId
     * @param array<int, Candle> $candles
     */
    public function __construct(
        public readonly int $evaluationId,
        public readonly array $candles,
    ) {
        $this->onQueue('charts');
    }

    public function handle(ChartRenderer $chartRenderer): void
    {
        $eval = StrategyEvaluation::find($this->evaluationId);
        if ($eval === null) {
            return;
        }

        try {
            $path = $chartRenderer->renderEvaluation($eval, $this->candles);
            if ($path !== null) {
                $eval->update(['chart_path' => $path]);
            }
        } catch (Throwable $e) {
            Log::warning('RenderEvaluationChartJob failed', [
                'evaluation_id' => $this->evaluationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
