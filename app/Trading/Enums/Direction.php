<?php

declare(strict_types=1);

namespace App\Trading\Enums;

/**
 * Side of a trade / signal.
 */
enum Direction: string
{
    case Long = 'LONG';
    case Short = 'SHORT';

    public function opposite(): self
    {
        return $this === self::Long ? self::Short : self::Long;
    }

    /** +1 for long, -1 for short — handy for price-direction math. */
    public function sign(): int
    {
        return $this === self::Long ? 1 : -1;
    }
}
