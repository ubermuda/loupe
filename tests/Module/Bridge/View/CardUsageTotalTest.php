<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Bridge\View\CardUsageTotal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardUsageTotalTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function counts(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'below a thousand' => [999, '999'];
        yield 'a round thousand' => [1000, '1k'];
        yield 'thousands' => [45_300, '45.3k'];
        yield 'rounds up to the next unit' => [999_960, '1M'];
        yield 'millions' => [1_234_567, '1.2M'];
        yield 'billions' => [3_050_000_000, '3.1B'];
    }

    #[DataProvider('counts')]
    public function test_it_shows_a_token_count_in_compact_form(int $count, string $expected): void
    {
        self::assertSame($expected, CardUsageTotal::compact($count));
    }
}
