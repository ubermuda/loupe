<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Billing\Service\PriceView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceViewTest extends TestCase
{
    /** @return iterable<string, array{int, string, float}> */
    public static function amounts(): iterable
    {
        yield 'two decimal places' => [900, 'eur', 9.0];
        yield 'zero decimal places' => [900, 'jpy', 900.0];
        yield 'three decimal places' => [9000, 'bhd', 9.0];
    }

    #[DataProvider('amounts')]
    public function test_minor_units_are_converted_with_the_currency_exponent(int $unitAmount, string $currency, float $expected): void
    {
        $price = new PriceView(priceId: 'price_1', unitAmount: $unitAmount, currency: $currency, interval: 'month');

        self::assertSame($expected, $price->amountInMajorUnits());
    }
}
