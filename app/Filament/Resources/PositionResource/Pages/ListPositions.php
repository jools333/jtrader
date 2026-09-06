<?php

declare(strict_types=1);

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use App\Models\Position;
use App\Trading\Services\BingXPositionSyncService;
use App\Trading\Services\DailyPositionReportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListPositions extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = PositionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

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
            Action::make('telegram_report')
                ->label('Отчет в Telegram')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Отправить отчет в Telegram')
                ->modalDescription('Сформировать и отправить ежедневный отчет по позициям за сегодня в Telegram-канал?')
                ->action(function (DailyPositionReportService $reportService) {
                    $res = $reportService->sendReport();
                    if ($res['sent']) {
                        Notification::make()
                            ->title('Отчет отправлен в Telegram')
                            ->body("Дата: {$res['date']} | Закрыто: {$res['closed_count']} | Чистый P&L: {$res['net_pnl']} USDT")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Ошибка отправки отчета')
                            ->body($res['error'] ?? 'Не удалось отправить сообщение в Telegram')
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('sync')
                ->label('Синхронизировать с BingX')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function (BingXPositionSyncService $syncService) {
                    $res = $syncService->sync();
                    Notification::make()
                        ->title('Синхронизация с BingX завершена')
                        ->body("Импортировано: {$res->imported}, Закрыто на бирже: {$res->closed}, Обновлено: {$res->updated}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
