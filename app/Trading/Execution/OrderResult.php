<?php

declare(strict_types=1);

namespace App\Trading\Execution;

/**
 * Normalised result of an order-routing call, independent of the exchange.
 */
final class OrderResult
{
    /** @param array<string, mixed> $raw exchange-native response payload */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $orderId = null,
        public readonly array $raw = [],
        public readonly ?string $error = null,
    ) {
    }

    public static function success(?string $orderId = null, array $raw = []): self
    {
        return new self(true, $orderId, $raw);
    }

    public static function failure(string $error, array $raw = []): self
    {
        return new self(false, null, $raw, $error);
    }

    /**
     * Average fill price from the exchange response, or null if not present / zero.
     * BingX swap v2 returns it at data.order.avgPrice; falls back to data.avgPrice.
     */
    public function filledPrice(): ?float
    {
        $price = (float) ($this->raw['data']['order']['avgPrice']
            ?? $this->raw['data']['avgPrice']
            ?? 0.0);

        return $price > 0.0 ? $price : null;
    }
}
