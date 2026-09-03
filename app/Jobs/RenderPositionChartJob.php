<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Market\DTO\Candle;
use App\Models\Position;
use App\Trading\Charting\ChartRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenderPositionChartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $positionId
     * @param array<int, Candle> $candles
     */
    public function __construct(
        public readonly int $positionId,
        public readonly array $candles,
    ) {
        $this->onQueue('charts');
    }

    public function handle(ChartRenderer $chartRenderer): void
    {
        $position = Position::find($this->positionId);
        if ($position === null) {
            return;
        }

        try {
            $path = $chartRenderer->render($position, $this->candles);
            if ($path !== null) {
                $position->update(['chart_path' => $path]);
            }
        } catch (Throwable $e) {
            Log::warning('RenderPositionChartJob failed', [
                'position_id' => $this->positionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
