<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\BridgeCommandTtl;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BridgeCommandTtlTest extends TestCase
{
    public function test_the_flag_sets_the_lifetime(): void
    {
        $ttl = new BridgeCommandTtl(FeatureFlags::service([BridgeCommandTtl::FLAG => 30]), 15);

        self::assertSame(30, $ttl->minutes());
        self::assertSame(
            '2026-09-29T12:30:00+00:00',
            $ttl->expiresAt(new \DateTimeImmutable('2026-09-29T12:00:00+00:00'))->format(\DateTimeInterface::ATOM),
        );
    }

    public function test_an_absent_flag_reads_the_default(): void
    {
        self::assertSame(15, new BridgeCommandTtl(FeatureFlags::service(), 15)->minutes());
    }

    #[DataProvider('valuesBelowOne')]
    public function test_a_value_below_one_reads_the_default(int $value): void
    {
        self::assertSame(15, new BridgeCommandTtl(FeatureFlags::service([BridgeCommandTtl::FLAG => $value]), 15)->minutes());
    }

    /** @return iterable<string, array{int}> */
    public static function valuesBelowOne(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    public function test_one_minute_is_allowed(): void
    {
        self::assertSame(1, new BridgeCommandTtl(FeatureFlags::service([BridgeCommandTtl::FLAG => 1]), 15)->minutes());
    }
}
