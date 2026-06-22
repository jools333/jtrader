<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PositionResource\Pages\ListPositions;
use App\Filament\Resources\PositionResource\Pages\ViewPosition;
use App\Models\Position;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitReason;
use App\Trading\Enums\ExitType;
use App\Trading\Enums\SignalType;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return 'Позиции';
    }

    public static function getModelLabel(): string
    {
        return 'Позиция';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Позиции';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Position::where('status', Position::STATUS_OPEN)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'success';
    }

    public static function table(Table $table): Table
    {
        $symbols = array_combine(
            (array) config('exchange.pairs', []),
            (array) config('exchange.pairs', [])
        );

        $intervals = array_combine(
            (array) config('exchange.intervals', []),
            (array) config('exchange.intervals', [])
        );

        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('symbol')
                    ->label('Пара')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('interval')
                    ->label('ТФ')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('direction')
                    ->label('Напр.')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Direction::Long->value  => 'success',
                        Direction::Short->value => 'danger',
                        default                 => 'gray',
                    }),

                TextColumn::make('signal_type')
                    ->label('Сигнал')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        SignalType::Bounce->value        => 'BOUNCE',
                        SignalType::Retest->value        => 'RETEST',
                        SignalType::FalseBreakout->value => 'FAKEOUT',
                        SignalType::TrendPullback->value => 'PULLBACK',
                        default                          => $state,
                    }),

                IconColumn::make('confluence')
                    ->label('Конфл.')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Position::STATUS_OPEN   => 'success',
                        Position::STATUS_CLOSED => 'gray',
                        default                 => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Position::STATUS_OPEN   => 'Открыта',
                        Position::STATUS_CLOSED => 'Закрыта',
                        default                 => $state,
                    }),

                TextColumn::make('entry_price')
                    ->label('Вход')
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),

                TextColumn::make('stop_price')
                    ->label('Стоп')
                    ->numeric(decimalPlaces: 4)
                    ->color('danger'),

                TextColumn::make('target1')
                    ->label('Цель 1')
                    ->numeric(decimalPlaces: 4)
                    ->color('success'),

                TextColumn::make('rr_ratio')
                    ->label('R:R')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('x')
                    ->badge()
                    ->color(fn (float $state): string => $state >= 2 ? 'success' : ($state >= 1 ? 'warning' : 'danger')),

                TextColumn::make('exit_price')
                    ->label('Выход')
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('realized_pnl')
                    ->label('P&L')
                    ->numeric(decimalPlaces: 4)
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state > 0      => 'success',
                        $state < 0      => 'danger',
                        default         => 'gray',
                    })
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('exit_type')
                    ->label('Причина')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        ExitType::Target1->value       => 'success',
                        ExitType::Target2->value       => 'success',
                        ExitType::StopLoss->value      => 'danger',
                        ExitType::EarlyReversal->value => 'warning',
                        default                        => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        ExitType::Target1->value       => 'TP1',
                        ExitType::Target2->value       => 'TP2',
                        ExitType::StopLoss->value      => 'SL',
                        ExitType::EarlyReversal->value => 'Разворот',
                        null                           => '—',
                        default                        => $state,
                    })
                    ->placeholder('—'),

                TextColumn::make('opened_at')
                    ->label('Открыта')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('closed_at')
                    ->label('Закрыта')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        Position::STATUS_OPEN   => 'Открыта',
                        Position::STATUS_CLOSED => 'Закрыта',
                    ])
                    ->placeholder('Все статусы'),

                SelectFilter::make('direction')
                    ->label('Направление')
                    ->options([
                        Direction::Long->value  => 'Long',
                        Direction::Short->value => 'Short',
                    ])
                    ->placeholder('Все направления'),

                SelectFilter::make('signal_type')
                    ->label('Тип сигнала')
                    ->options([
                        SignalType::Bounce->value        => 'Bounce',
                        SignalType::Retest->value        => 'Retest',
                        SignalType::FalseBreakout->value => 'False Breakout',
                        SignalType::TrendPullback->value => 'Trend Pullback',
                    ])
                    ->placeholder('Все сигналы'),

                SelectFilter::make('symbol')
                    ->label('Пара')
                    ->options($symbols)
                    ->placeholder('Все пары'),

                SelectFilter::make('interval')
                    ->label('Таймфрейм')
                    ->options($intervals)
                    ->placeholder('Все ТФ'),

                Filter::make('confluence')
                    ->label('Только конфлюэнс')
                    ->query(fn (Builder $query) => $query->where('confluence', true))
                    ->toggle(),

                Filter::make('profitable')
                    ->label('Прибыльные')
                    ->query(fn (Builder $query) => $query->where('realized_pnl', '>', 0))
                    ->toggle(),
            ])
            ->actions([
                ViewAction::make()->label(''),
            ])
            ->striped()
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Позиция')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('symbol')->label('Пара')->badge(),
                        TextEntry::make('interval')->label('ТФ')->badge()->color('gray'),
                        TextEntry::make('direction')
                            ->label('Направление')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                Direction::Long->value  => 'success',
                                Direction::Short->value => 'danger',
                                default                 => 'gray',
                            }),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                Position::STATUS_OPEN   => 'success',
                                Position::STATUS_CLOSED => 'gray',
                                default                 => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                Position::STATUS_OPEN   => 'Открыта',
                                Position::STATUS_CLOSED => 'Закрыта',
                                default                 => $state,
                            }),
                    ]),

                    Grid::make(3)->schema([
                        TextEntry::make('signal_type')->label('Тип сигнала')->badge()->color('info'),
                        IconEntry::make('confluence')->label('Конфлюэнс')->boolean(),
                        TextEntry::make('rr_ratio')->label('R:R')->suffix('x')
                            ->badge()
                            ->color(fn (float $state): string => $state >= 2 ? 'success' : ($state >= 1 ? 'warning' : 'danger')),
                    ]),
                ]),

            Section::make('Торговый план')
                ->columns(4)
                ->schema([
                    TextEntry::make('entry_price')->label('Цена входа')->numeric(decimalPlaces: 6),
                    TextEntry::make('stop_price')->label('Стоп-лосс')->numeric(decimalPlaces: 6)->color('danger'),
                    TextEntry::make('target1')->label('Цель 1 (TP1)')->numeric(decimalPlaces: 6)->color('success'),
                    TextEntry::make('target2')->label('Цель 2 (TP2)')->numeric(decimalPlaces: 6)->color('success'),
                    TextEntry::make('quantity')->label('Количество')->numeric(decimalPlaces: 6),
                    TextEntry::make('size')->label('Размер позиции')->suffix('x')->numeric(decimalPlaces: 2),
                    TextEntry::make('entry_order_id')->label('Order ID (вход)')->placeholder('—')->copyable(),
                    TextEntry::make('opened_at')->label('Открыта')->dateTime('d.m.Y H:i:s'),
                ]),

            Section::make('Результат')
                ->visible(fn (Position $record): bool => $record->status === Position::STATUS_CLOSED)
                ->columns(4)
                ->schema([
                    TextEntry::make('exit_type')
                        ->label('Тип выхода')
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            ExitType::Target1->value       => 'success',
                            ExitType::Target2->value       => 'success',
                            ExitType::StopLoss->value      => 'danger',
                            ExitType::EarlyReversal->value => 'warning',
                            default                        => 'gray',
                        })
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            ExitType::Target1->value       => 'Take Profit 1',
                            ExitType::Target2->value       => 'Take Profit 2',
                            ExitType::StopLoss->value      => 'Stop Loss',
                            ExitType::EarlyReversal->value => 'Ранний разворот',
                            default                        => $state ?? '—',
                        }),
                    TextEntry::make('exit_reason')
                        ->label('Причина')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            ExitReason::Divergence->value => 'Дивергенция',
                            ExitReason::Absorption->value => 'Поглощение',
                            ExitReason::EmaTurn->value    => 'Разворот EMA',
                            null                          => '—',
                            default                       => $state,
                        }),
                    TextEntry::make('exit_price')->label('Цена выхода')->numeric(decimalPlaces: 6)->placeholder('—'),
                    TextEntry::make('realized_pnl')
                        ->label('P&L')
                        ->numeric(decimalPlaces: 6)
                        ->placeholder('—')
                        ->color(fn (?float $state): string => match (true) {
                            $state === null => 'gray',
                            $state > 0      => 'success',
                            $state < 0      => 'danger',
                            default         => 'gray',
                        }),
                    TextEntry::make('exit_order_id')->label('Order ID (выход)')->placeholder('—')->copyable(),
                    TextEntry::make('closed_at')->label('Закрыта')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                ]),

            Section::make('График')
                ->visible(fn (Position $record): bool => $record->chart_path !== null)
                ->schema([
                    ImageEntry::make('chart_path')
                        ->label('')
                        ->disk('public')
                        ->columnSpanFull()
                        ->height(500)
                        ->url(fn (Position $record): string => asset('storage/'.$record->chart_path))
                        ->openUrlInNewTab(),
                ]),

            Section::make('Контекст входа')
                ->collapsed()
                ->schema([
                    KeyValueEntry::make('entry_context')
                        ->label('')
                        ->columnSpanFull()
                        ->getStateUsing(fn (Position $record): array => collect($record->entry_context ?? [])
                            ->map(fn ($v) => is_array($v) ? json_encode($v) : (string) $v)
                            ->all()),
                ]),

            Section::make('Контекст выхода')
                ->collapsed()
                ->visible(fn (Position $record): bool => ! empty($record->exit_context))
                ->schema([
                    KeyValueEntry::make('exit_context')
                        ->label('')
                        ->columnSpanFull()
                        ->getStateUsing(fn (Position $record): array => collect($record->exit_context ?? [])
                            ->map(fn ($v) => is_array($v) ? json_encode($v) : (string) $v)
                            ->all()),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPositions::route('/'),
            'view'  => ViewPosition::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Торговля';
    }
}
