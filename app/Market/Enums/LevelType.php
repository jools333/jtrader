<?php

declare(strict_types=1);

namespace App\Market\Enums;

enum LevelType: string
{
    case Support = 'support';
    case Resistance = 'resistance';

    public function label(): string
    {
        return $this === self::Support ? 'Поддержка' : 'Сопротивление';
    }

    public function color(): string
    {
        return $this === self::Support ? '#26a69a' : '#ef5350';
    }
}
