<?php

declare(strict_types=1);

namespace App\Trading\Support;

/**
 * Utility for contract price and quantity precision according to exchange specifications.
 */
final class ContractPrecision
{
    /**
     * Default known precisions for USDT-M perpetual contracts (BingX).
     *
     * @var array<string, array{price: int, quantity: int}>
     */
    private const DEFAULT_PRECISIONS = [
        'BTC-USDT'  => ['price' => 1, 'quantity' => 4],
        'ETH-USDT'  => ['price' => 2, 'quantity' => 2],
        'BNB-USDT'  => ['price' => 2, 'quantity' => 2],
        'SOL-USDT'  => ['price' => 3, 'quantity' => 2],
        'LINK-USDT' => ['price' => 3, 'quantity' => 1],
        'ADA-USDT'  => ['price' => 4, 'quantity' => 0],
        'XRP-USDT'  => ['price' => 4, 'quantity' => 0],
        'DOGE-USDT' => ['price' => 5, 'quantity' => 0],
    ];

    /**
     * Get price decimals for a symbol.
     */
    public static function priceDecimals(?string $symbol, float $price = 0.0): int
    {
        if ($symbol !== null) {
            $configured = config("exchange.precisions.{$symbol}.price");
            if (is_int($configured)) {
                return $configured;
            }
            if (isset(self::DEFAULT_PRECISIONS[$symbol]['price'])) {
                return self::DEFAULT_PRECISIONS[$symbol]['price'];
            }
        }

        // Fallback heuristic based on price magnitude
        if ($price >= 100.0) {
            return 2;
        }
        if ($price >= 1.0) {
            return 4;
        }

        return 6;
    }

    /**
     * Get quantity decimals for a symbol.
     */
    public static function quantityDecimals(?string $symbol): int
    {
        if ($symbol !== null) {
            $configured = config("exchange.precisions.{$symbol}.quantity");
            if (is_int($configured)) {
                return $configured;
            }
            if (isset(self::DEFAULT_PRECISIONS[$symbol]['quantity'])) {
                return self::DEFAULT_PRECISIONS[$symbol]['quantity'];
            }
        }

        return 4;
    }

    /**
     * Round price to the exact contract price precision.
     */
    public static function roundPrice(float $price, ?string $symbol): float
    {
        return round($price, self::priceDecimals($symbol, $price));
    }

    /**
     * Round quantity to the exact contract quantity precision.
     * For integer quantities (decimals = 0), floor() is used to avoid exceeding risk/notional caps.
     */
    public static function roundQuantity(float $quantity, ?string $symbol): float
    {
        $decimals = self::quantityDecimals($symbol);
        if ($decimals === 0) {
            return (float) (int) floor($quantity);
        }

        return round($quantity, $decimals);
    }
}
