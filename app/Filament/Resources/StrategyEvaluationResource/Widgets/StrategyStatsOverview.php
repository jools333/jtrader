<?php

declare(strict_types=1);

namespace App\Filament\Resources\StrategyEvaluationResource\Widgets;

use App\Models\StrategyEvaluation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StrategyStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $total = StrategyEvaluation::count();
        $completed = StrategyEvaluation::where('status', StrategyEvaluation::STATUS_COMPLETED)->count();
        $partial = StrategyEvaluation::where('status', StrategyEvaluation::STATUS_PARTIAL)->count();

        $convRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

        // Top missing criteria
        $allMissing = StrategyEvaluation::whereNotNull('missing_criteria')
            ->pluck('missing_criteria')
            ->flatten()
            ->filter();

        $topMissing = $allMissing->countBy()->sortDesc()->keys()->first() ?? '—';

        return [
            Stat::make('Всего сетапов (≥ 50%)', (string) $total)
                ->description("Частичных: {$partial}, Завершенных: {$completed}")
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('info'),

            Stat::make('Конверсия в сигнал (100%)', "{$convRate}%")
                ->description("{$completed} из {$total} сетапов")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($convRate >= 20 ? 'success' : ($convRate >= 10 ? 'warning' : 'gray')),

            Stat::make('Топ причина срыва сетапа', (string) $topMissing)
                ->description('Чаще всего не хватает этого условия')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
