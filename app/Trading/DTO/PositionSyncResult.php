<?php

declare(strict_types=1);

namespace App\Trading\DTO;

final class PositionSyncResult
{
    /**
     * @param list<string> $messages
     */
    public function __construct(
        public int $imported = 0,
        public int $closed = 0,
        public int $updated = 0,
        public array $messages = [],
    ) {
    }

    public function totalChanges(): int
    {
        return $this->imported + $this->closed + $this->updated;
    }
}
