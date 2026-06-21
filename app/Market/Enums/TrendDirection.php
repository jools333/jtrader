<?php

declare(strict_types=1);

namespace App\Market\Enums;

enum TrendDirection: string
{
    case Up = 'up';
    case Down = 'down';
    case Sideways = 'sideways';

    public function label(): string
    {
        return match ($this) {
            self::Up => 'Восходящий',
            self::Down => 'Нисходящий',
            self::Sideways => 'Боковик',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Up => '#26a69a',
            self::Down => '#ef5350',
            self::Sideways => '#b2b5be',
        };
    }
}
