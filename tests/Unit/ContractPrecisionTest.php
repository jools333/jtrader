<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Trading\Support\ContractPrecision;
use Tests\TestCase;

final class ContractPrecisionTest extends TestCase
{
    public function test_price_decimals_for_configured_symbols(): void
    {
        $this->assertSame(1, ContractPrecision::priceDecimals('BTC-USDT'));
        $this->assertSame(2, ContractPrecision::priceDecimals('ETH-USDT'));
        $this->assertSame(2, ContractPrecision::priceDecimals('BNB-USDT'));
        $this->assertSame(3, ContractPrecision::priceDecimals('SOL-USDT'));
        $this->assertSame(3, ContractPrecision::priceDecimals('LINK-USDT'));
        $this->assertSame(4, ContractPrecision::priceDecimals('ADA-USDT'));
        $this->assertSame(4, ContractPrecision::priceDecimals('XRP-USDT'));
        $this->assertSame(5, ContractPrecision::priceDecimals('DOGE-USDT'));
    }

    public function test_quantity_decimals_for_configured_symbols(): void
    {
        $this->assertSame(4, ContractPrecision::quantityDecimals('BTC-USDT'));
        $this->assertSame(2, ContractPrecision::quantityDecimals('ETH-USDT'));
        $this->assertSame(2, ContractPrecision::quantityDecimals('BNB-USDT'));
        $this->assertSame(2, ContractPrecision::quantityDecimals('SOL-USDT'));
        $this->assertSame(1, ContractPrecision::quantityDecimals('LINK-USDT'));
        $this->assertSame(0, ContractPrecision::quantityDecimals('ADA-USDT'));
        $this->assertSame(0, ContractPrecision::quantityDecimals('XRP-USDT'));
        $this->assertSame(0, ContractPrecision::quantityDecimals('DOGE-USDT'));
    }

    public function test_fallback_heuristics_for_unknown_symbols(): void
    {
        $this->assertSame(2, ContractPrecision::priceDecimals('UNKNOWN-USDT', 5000.0));
        $this->assertSame(4, ContractPrecision::priceDecimals('UNKNOWN-USDT', 5.5));
        $this->assertSame(6, ContractPrecision::priceDecimals('UNKNOWN-USDT', 0.05));

        $this->assertSame(4, ContractPrecision::quantityDecimals('UNKNOWN-USDT'));
    }

    public function test_round_price_exactness(): void
    {
        // ADA has 4 decimals
        $this->assertSame(0.2555, ContractPrecision::roundPrice(0.255502, 'ADA-USDT'));
        // SOL has 3 decimals
        $this->assertSame(120.208, ContractPrecision::roundPrice(120.208123, 'SOL-USDT'));
        // DOGE has 5 decimals
        $this->assertSame(0.09621, ContractPrecision::roundPrice(0.0962099, 'DOGE-USDT'));
        // LINK has 3 decimals
        $this->assertSame(14.405, ContractPrecision::roundPrice(14.4054, 'LINK-USDT'));
    }

    public function test_round_quantity_floors_integer_contracts(): void
    {
        // ADA, XRP, DOGE must floor integer quantities to never exceed risk/notional caps
        $this->assertSame(11529.0, ContractPrecision::roundQuantity(11529.98, 'ADA-USDT'));
        $this->assertSame(2000.0, ContractPrecision::roundQuantity(2000.75, 'XRP-USDT'));
        $this->assertSame(31500.0, ContractPrecision::roundQuantity(31500.4, 'DOGE-USDT'));

        // SOL has 2 decimals
        $this->assertSame(26.13, ContractPrecision::roundQuantity(26.134, 'SOL-USDT'));
        // LINK has 1 decimal
        $this->assertSame(15.2, ContractPrecision::roundQuantity(15.24, 'LINK-USDT'));
    }
}
