<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\CliCompatibility;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CliCompatibilityTest extends TestCase
{
    #[TestWith(['1.0.0', true])]
    #[TestWith(['1.4.2', true])]
    #[TestWith(['1.0.0-rc.1', true])]
    #[TestWith(['2.0.0', false])]
    #[TestWith(['0.9.0', false])]
    #[TestWith(['a1b2c3d', false])]
    #[TestWith(['03cc8ba4f1e2d3c4b5a6978877665544332211aa', false])]
    #[TestWith(['b4e39aa7 (dirty)', false])]
    #[TestWith(['', false])]
    public function test_it_accepts_a_version_inside_the_range(string $version, bool $expected): void
    {
        self::assertSame($expected, new CliCompatibility()->isCompatible($version));
    }

    public function test_the_major_version_comes_from_the_range(): void
    {
        self::assertSame(1, CliCompatibility::major());
        self::assertSame(12, CliCompatibility::major('^12.3'));
    }

    #[TestWith(['~1.0'])]
    #[TestWith(['>=1.0'])]
    #[TestWith(['^x.0'])]
    public function test_a_range_of_another_form_has_no_major(string $range): void
    {
        $this->expectException(\LogicException::class);

        CliCompatibility::major($range);
    }
}
