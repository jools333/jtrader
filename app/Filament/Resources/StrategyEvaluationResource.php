<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StrategyEvaluationResource\Pages\ListStrategyEvaluations;
use App\Filament\Resources\StrategyEvaluationResource\Widgets\StrategyStatsOverview;
use App\Models\StrategyEvaluation;
use App\Trading\Enums\Direction;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StrategyEvaluationResource extends Resource
{
    protected static ?string $model = StrategyEvaluation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Статистика стратегий';
    }

    public static function getModelLabel(): string
    {
        return 'Сетап стратегии';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Анализ сетапов';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = StrategyEvaluation::where('status', StrategyEvaluation::STATUS_COMPLETED)->count();

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
            ->defaultSort('evaluated_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('symbol')
                    ->label('Пара')
                    ->badge()
                    ->placeholder('—')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('interval')
                    ->label('ТФ')
                    ->badge()
                    ->placeholder('—')
                    ->color('gray'),

                TextColumn::make('strategy')
                    ->label('Стратегия')
                    ->badge()
                    ->color('info'),

                TextColumn::make('direction')
                    ->label('Напр.')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Direction::Long->value => 'success',
                        Direction::Short->value => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('score')
                    ->label('Прогресс')
                    ->badge()
                    ->color(fn (float $state): string => match (true) {
                        $state >= 100.0 => 'success',
                        $state >= 70.0 => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (StrategyEvaluation $record): string => sprintf('%.1f%% (%d/%d)', $record->score, $record->passed_count, $record->total_count))
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        StrategyEvaluation::STATUS_COMPLETED => 'success',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        StrategyEvaluation::STATUS_COMPLETED => 'Вход (100%)',
                        default => 'Частично',
                    }),

                TextColumn::make('missing_criteria')
                    ->label('Чего не хватило')
                    ->badge()
                    ->color('danger')
                    ->separator(',')
                    ->limitList(2)
                    ->placeholder('— (100% вход)')
                    ->wrap(),

                TextColumn::make('current_price')
                    ->label('Цена')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('level')
                    ->label('Уровень')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('atr')
                    ->label('ATR')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('rr_ratio')
                    ->label('R:R')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('x')
                    ->placeholder('—'),

                TextColumn::make('evaluated_at')
                    ->label('Время')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('symbol')
                    ->label('Пара')
                    ->options($symbols),

                SelectFilter::make('interval')
                    ->label('Таймфрейм')
                    ->options($intervals),

                SelectFilter::make('direction')
                    ->label('Направление')
                    ->options([
                        Direction::Long->value => 'LONG',
                        Direction::Short->value => 'SHORT',
                    ]),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        StrategyEvaluation::STATUS_COMPLETED => 'Завершенные (100%)',
                        StrategyEvaluation::STATUS_PARTIAL => 'Частичные (< 100%)',
                    ]),

                Filter::make('completed_only')
                    ->label('Только сигналы 100%')
                    ->query(fn (Builder $query) => $query->where('status', StrategyEvaluation::STATUS_COMPLETED))
                    ->toggle(),

                Filter::make('high_score')
                    ->label('Прогресс ≥ 70%')
                    ->query(fn (Builder $query) => $query->where('score', '>=', 70.0))
                    ->toggle(),
            ])
            ->actions([
                ViewAction::make()->label('Детали'),
            ])
            ->striped()
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Информация о сетапе')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('symbol')->label('Пара')->badge()->placeholder('—'),
                        TextEntry::make('interval')->label('ТФ')->badge()->color('gray')->placeholder('—'),
                        TextEntry::make('strategy')->label('Стратегия')->badge()->color('info'),
                        TextEntry::make('direction')
                            ->label('Направление')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                Direction::Long->value => 'success',
                                Direction::Short->value => 'danger',
                                default => 'gray',
                            }),
                    ]),

                    Grid::make(4)->schema([
                        TextEntry::make('score')
                            ->label('Прогресс алгоритма')
                            ->badge()
                            ->color(fn (float $state): string => match (true) {
                                $state >= 100.0 => 'success',
                                $state >= 70.0 => 'warning',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (StrategyEvaluation $record): string => sprintf('%.1f%% (%d из %d условий)', $record->score, $record->passed_count, $record->total_count)),

                        TextEntry::make('status')
                            ->label('Итоговый статус')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                StrategyEvaluation::STATUS_COMPLETED => 'success',
                                default => 'warning',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                StrategyEvaluation::STATUS_COMPLETED => 'Вход (100% готовность)',
                                default => 'Частично сформирован',
                            }),

                        TextEntry::make('current_price')->label('Цена на свече')->numeric(decimalPlaces: 4),
                        TextEntry::make('evaluated_at')->label('Время анализа')->dateTime('d.m.Y H:i:s'),
                    ]),
                ]),

            Section::make('Параметры рынка')
                ->columns(3)
                ->schema([
                    TextEntry::make('level')->label('Ключевой уровень')->numeric(decimalPlaces: 4),
                    TextEntry::make('atr')->label('Волатильность (ATR)')->numeric(decimalPlaces: 4),
                    TextEntry::make('rr_ratio')->label('R:R план')->numeric(decimalPlaces: 2)->suffix('x')->placeholder('—'),
                ]),

            Section::make('Чек-лист условий алгоритма (Критерии)')
                ->schema([
                    KeyValueEntry::make('criteria_breakdown')
                        ->label('')
                        ->columnSpanFull()
                        ->getStateUsing(function (StrategyEvaluation $record): array {
                            $items = [];
                            foreach ($record->criteria_breakdown ?? [] as $k => $info) {
                                $statusIcon = ($info['passed'] ?? false) ? '✓ ВЫПОЛНЕНО' : '✗ НЕ ВЫПОЛНЕНО';
                                $name = $info['name'] ?? $k;
                                $expected = $info['expected'] ?? '';
                                $actual = $info['actual'] ?? '';
                                $items["[{$statusIcon}] {$name}"] = "Ожидалось: {$expected} | Факт: {$actual}";
                            }

                            return $items;
                        }),
                ]),

            Section::make('Несработавшие условия («Чего не хватило»)')
                ->visible(fn (StrategyEvaluation $record): bool => ! empty($record->missing_criteria))
                ->schema([
                    TextEntry::make('missing_criteria')
                        ->label('')
                        ->badge()
                        ->color('danger')
                        ->separator(','),
                ]),

            Section::make('1. График в момент сетапа')
                ->visible(fn (StrategyEvaluation $record): bool => $record->chart_path !== null)
                ->schema([
                    \Filament\Infolists\Components\ImageEntry::make('chart_path')
                        ->label('')
                        ->disk('public')
                        ->columnSpanFull()
                        ->height(450)
                        ->url(fn (StrategyEvaluation $record): string => '/storage/'.$record->chart_path)
                        ->openUrlInNewTab(),
                ]),

            Section::make('2. График исхода (+30 свечей)')
                ->schema([
                    \Filament\Infolists\Components\ImageEntry::make('outcome_chart_path')
                        ->label('')
                        ->disk('public')
                        ->columnSpanFull()
                        ->height(450)
                        ->visible(fn (StrategyEvaluation $record): bool => $record->outcome_chart_path !== null)
                        ->url(fn (StrategyEvaluation $record): string => '/storage/'.$record->outcome_chart_path)
                        ->openUrlInNewTab(),

                    TextEntry::make('outcome_status')
                        ->label('')
                        ->visible(fn (StrategyEvaluation $record): bool => $record->outcome_chart_path === null)
                        ->default('⏳ Ожидается закрытие 30 свечей после точки сетапа для построения графика исхода.')
                        ->color('gray'),
                ]),

            Section::make('Торговый план')
                ->visible(fn (StrategyEvaluation $record): bool => $record->entry_price !== null)
                ->columns(4)
                ->schema([
                    TextEntry::make('entry_price')->label('Вход')->numeric(decimalPlaces: 4),
                    TextEntry::make('stop_price')->label('Стоп-лосс')->numeric(decimalPlaces: 4)->color('danger'),
                    TextEntry::make('target1')->label('Цель 1')->numeric(decimalPlaces: 4)->color('success'),
                    TextEntry::make('target2')->label('Цель 2')->numeric(decimalPlaces: 4)->color('success'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStrategyEvaluations::route('/'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            StrategyStatsOverview::class,
        ];
    }
}
