<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit record of a strategy evaluation (completed or partial >= 50%).
 *
 * @property int $id
 * @property string|null $symbol
 * @property string|null $interval
 * @property string $strategy
 * @property string $direction
 * @property string $status
 * @property float $score
 * @property int $passed_count
 * @property int $total_count
 * @property float $level
 * @property float $atr
 * @property float $current_price
 * @property float|null $entry_price
 * @property float|null $stop_price
 * @property float|null $target1
 * @property float|null $target2
 * @property float|null $rr_ratio
 * @property array<int, string>|null $missing_criteria
 * @property array<string, array{name: string, passed: bool, expected: string, actual: string}>|null $criteria_breakdown
 * @property array<string, mixed>|null $indicators
 * @property int|null $candle_open_time
 * @property string|null $chart_path
 * @property string|null $outcome_chart_path
 * @property \Illuminate\Support\Carbon $evaluated_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class StrategyEvaluation extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'passed_count' => 'integer',
            'total_count' => 'integer',
            'level' => 'float',
            'atr' => 'float',
            'current_price' => 'float',
            'entry_price' => 'float',
            'stop_price' => 'float',
            'target1' => 'float',
            'target2' => 'float',
            'rr_ratio' => 'float',
            'missing_criteria' => 'array',
            'criteria_breakdown' => 'array',
            'indicators' => 'array',
            'candle_open_time' => 'integer',
            'evaluated_at' => 'datetime',
        ];
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopePartial(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PARTIAL);
    }

    public function scopeHighScore(Builder $query, float $threshold = 50.0): Builder
    {
        return $query->where('score', '>=', $threshold);
    }
}
