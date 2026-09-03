<?php

declare(strict_types=1);

namespace App\Models;

use App\Trading\Enums\Direction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A position opened by the trading agent, with the entry/exit rationale logged
 * for audit and later performance review.
 *
 * @property string $symbol
 * @property string $interval
 * @property string $direction
 * @property string $signal_type
 * @property string $status
 * @property float $entry_price
 * @property float $stop_price
 * @property float $target1
 * @property float $target2
 * @property float $size
 * @property array|null $entry_context
 * @property array|null $exit_context
 */
class Position extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'symbol', 'interval', 'direction', 'signal_type', 'confluence', 'status',
        'entry_price', 'stop_price', 'target1', 'target2', 'rr_ratio', 'quantity', 'size',
        'exit_type', 'exit_reason', 'exit_price', 'realized_pnl',
        'commission', 'funding_fee', 'leverage',
        'entry_context', 'exit_context', 'chart_path',
        'entry_order_id', 'exit_order_id', 'external_id',
        'opened_at', 'closed_at', 'synced_at',
    ];

    protected $casts = [
        'confluence' => 'boolean',
        'entry_price' => 'float',
        'stop_price' => 'float',
        'target1' => 'float',
        'target2' => 'float',
        'rr_ratio' => 'float',
        'quantity' => 'float',
        'size' => 'float',
        'exit_price' => 'float',
        'realized_pnl' => 'float',
        'commission' => 'float',
        'funding_fee' => 'float',
        'leverage' => 'integer',
        'entry_context' => 'array',
        'exit_context' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /** @param Builder<Position> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function direction(): Direction
    {
        return Direction::from($this->direction);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Net realized PnL after deducting exchange trading fees and funding fees.
     */
    public function netPnl(): ?float
    {
        if ($this->realized_pnl === null) {
            return null;
        }

        return $this->realized_pnl - ($this->commission ?? 0.0) - ($this->funding_fee ?? 0.0);
    }
}
