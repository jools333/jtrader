<?php

declare(strict_types=1);

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use App\Models\Position;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewPosition extends ViewRecord
{
    protected static string $resource = PositionResource::class;

    protected Width | string | null $maxContentWidth = Width::Full;

    public function getTitle(): string
    {
        /** @var Position $record */
        $record = $this->record;

        return "{$record->symbol} / {$record->interval} — #{$record->id}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('← Назад')
                ->url(PositionResource::getUrl('index'))
                ->color('gray'),
        ];
    }
}
