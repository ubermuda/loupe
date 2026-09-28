<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Service\FailedCheckNames;
use PHPUnit\Framework\TestCase;

final class FailedCheckNamesTest extends TestCase
{
    public function test_it_removes_braces_quotes_backslashes_and_control_characters(): void
    {
        self::assertSame(['lint js', 'test'], FailedCheckNames::clean(['{lint} "js"\\', "te\x00s\x1Ft\u{0085}"]));
    }

    public function test_it_trims_and_drops_empty_names(): void
    {
        self::assertSame(['phpunit'], FailedCheckNames::clean(['  phpunit  ', '   ', '{}', "\t\n"]));
    }

    public function test_it_cuts_a_name_to_200_characters(): void
    {
        $cleaned = FailedCheckNames::clean([str_repeat('é', 250)]);

        self::assertSame([str_repeat('é', 200)], $cleaned);
    }

    public function test_it_keeps_at_most_100_names(): void
    {
        $names = array_map(static fn (int $i): string => 'check-'.$i, range(1, 150));

        $cleaned = FailedCheckNames::clean([' ', ...$names]);

        self::assertCount(100, $cleaned);
        self::assertSame('check-1', $cleaned[0]);
        self::assertSame('check-100', $cleaned[99]);
    }

    public function test_it_replaces_invalid_utf8_rather_than_failing(): void
    {
        $cleaned = FailedCheckNames::clean(["build \xC3\x28"]);

        self::assertCount(1, $cleaned);
        self::assertTrue(mb_check_encoding($cleaned[0], 'UTF-8'));
        self::assertStringStartsWith('build ', $cleaned[0]);
    }
}
