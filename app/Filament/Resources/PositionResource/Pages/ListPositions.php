<?php

declare(strict_types=1);

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use App\Models\Position;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListPositions extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = PositionResource::class;

    protected Width | string | null $maxContentWidth = Width::Full;

    public function getTitle(): string
    {
        return 'Позиции';
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Все')
                ->badge(Position::count()),

            'open' => Tab::make('Открытые')
                ->badge(Position::where('status', Position::STATUS_OPEN)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Position::STATUS_OPEN)),

            'closed' => Tab::make('Закрытые')
                ->badge(Position::where('status', Position::STATUS_CLOSED)->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Position::STATUS_CLOSED)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('sync')
                ->label('Синхронизировать с BingX')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function (\App\Trading\Services\BingXPositionSyncService $syncService) {
                    $res = $syncService->sync();
                    \Filament\Notifications\Notification::make()
                        ->title('Синхронизация с BingX завершена')
                        ->body("Импортировано: {$res->imported}, Закрыто на бирже: {$res->closed}, Обновлено: {$res->updated}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
