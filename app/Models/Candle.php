<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted OHLCV row. The analysis layer works with the lightweight
 * {@see \App\Market\DTO\Candle} value object instead of this Eloquent model.
 *
 * @property string $symbol
 * @property string $interval
 * @property int $open_time
 * @property int $close_time
 */
class Candle extends Model
{
    protected $fillable = [
        'symbol', 'interval', 'open_time',
        'open', 'high', 'low', 'close', 'volume', 'close_time',
    ];

    protected $casts = [
        'open_time' => 'integer',
        'close_time' => 'integer',
        'open' => 'float',
        'high' => 'float',
        'low' => 'float',
        'close' => 'float',
        'volume' => 'float',
    ];
}
