<?php

declare(strict_types=1);

namespace App\Trading\DTO;

use App\Trading\Enums\Direction;

/**
 * Encapsulates full diagnostic scoring and analysis result of a strategy evaluation.
 */
final class StrategyEvaluationResult
{
    /**
     * @param array<string, CriterionResult> $criteria
     * @param list<string> $missingCriteria
     * @param array<string, mixed>|null $indicators
     */
    public function __construct(
        public readonly string $strategy,
        public readonly Direction $direction,
        public readonly float $score,
        public readonly int $passedCount,
        public readonly int $totalCount,
        public readonly bool $isFullSignal,
        public readonly ?EntrySignal $entrySignal,
        public readonly float $level,
        public readonly float $atr,
        public readonly float $currentPrice,
        public readonly array $criteria,
        public readonly array $missingCriteria,
        public readonly ?array $indicators = null,
        public readonly ?string $symbol = null,
        public readonly ?string $interval = null,
        public readonly ?int $candleOpenTime = null,
        public readonly ?array $candles = null,
    ) {
    }

    /**
     * Convert criteria breakdown to array for JSON persistence.
     *
     * @return array<string, array{name: string, passed: bool, expected: string, actual: string}>
     */
    public function criteriaToArray(): array
    {
        $res = [];
        foreach ($this->criteria as $key => $criterion) {
            $res[$key] = $criterion->toArray();
        }

        return $res;
    }
}
