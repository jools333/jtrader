<?php

declare(strict_types=1);

namespace App\Filament\Resources\StrategyEvaluationResource\Pages;

use App\Filament\Resources\StrategyEvaluationResource;
use App\Filament\Resources\StrategyEvaluationResource\Widgets\StrategyStatsOverview;
use App\Models\StrategyEvaluation;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListStrategyEvaluations extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = StrategyEvaluationResource::class;

    protected Width | string | null $maxContentWidth = Width::Full;

    public function getTitle(): string
    {
        return 'Статистика стратегий и анализ сетапов';
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Все (≥ 50%)')
                ->badge(StrategyEvaluation::count()),

            'completed' => Tab::make('Вход 100%')
                ->badge(StrategyEvaluation::where('status', StrategyEvaluation::STATUS_COMPLETED)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StrategyEvaluation::STATUS_COMPLETED)),

            'high_score' => Tab::make('Близко (≥ 70%)')
                ->badge(StrategyEvaluation::where('score', '>=', 70.0)->where('score', '<', 100.0)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('score', '>=', 70.0)->where('score', '<', 100.0)),

            'partial' => Tab::make('Частичные (< 70%)')
                ->badge(StrategyEvaluation::where('score', '<', 70.0)->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('score', '<', 70.0)),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            StrategyStatsOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
