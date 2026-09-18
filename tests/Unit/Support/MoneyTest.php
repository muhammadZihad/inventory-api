<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_dollar_amounts_are_converted_to_cents_for_calculation(): void
    {
        $this->assertSame(12500, Money::dollarsToCents('125.00'));
        $this->assertSame(12550, Money::dollarsToCents('125.50'));
        $this->assertSame(1000, Money::dollarsToCents(10));
    }

    public function test_cents_are_converted_to_dollar_strings_for_storage_and_responses(): void
    {
        $this->assertSame('125.00', Money::centsToDollars(12500));
        $this->assertSame('30.05', Money::centsToDollars(3005));
    }
}
