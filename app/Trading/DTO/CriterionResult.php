<?php

declare(strict_types=1);

namespace App\Trading\DTO;

/**
 * Diagnostic result of a single strategy condition/criterion.
 */
final class CriterionResult
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $expected,
        public readonly string $actual,
        public readonly ?float $actualValue = null,
        public readonly ?float $thresholdValue = null,
    ) {
    }

    /**
     * @return array{name: string, passed: bool, expected: string, actual: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'passed' => $this->passed,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }
}
