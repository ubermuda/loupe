<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\HostSampling;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostSamplingTest extends TestCase
{
    public function test_absent_flags_read_the_defaults(): void
    {
        $sampling = new HostSampling(FeatureFlags::service(), false, 60);

        self::assertFalse($sampling->enabled());
        self::assertSame(60, $sampling->intervalSeconds());
    }

    public function test_the_flags_set_the_values(): void
    {
        $sampling = new HostSampling(
            FeatureFlags::service([HostSampling::ENABLED_FLAG => true, HostSampling::INTERVAL_FLAG => 30]),
            false,
            60,
        );

        self::assertTrue($sampling->enabled());
        self::assertSame(30, $sampling->intervalSeconds());
    }

    public function test_a_flag_switched_off_overrides_a_default_that_is_on(): void
    {
        $sampling = new HostSampling(FeatureFlags::service([HostSampling::ENABLED_FLAG => false]), true, 60);

        self::assertFalse($sampling->enabled());
    }

    #[DataProvider('intervals')]
    public function test_an_interval_below_the_floor_reads_the_default(int $stored, int $read): void
    {
        $sampling = new HostSampling(FeatureFlags::service([HostSampling::INTERVAL_FLAG => $stored]), false, 60);

        self::assertSame($read, $sampling->intervalSeconds());
    }

    /** @return iterable<string, array{int, int}> */
    public static function intervals(): iterable
    {
        yield 'negative' => [-5, 60];
        yield 'zero' => [0, 60];
        yield 'one below the floor' => [HostSampling::MIN_INTERVAL_SECONDS - 1, 60];
        yield 'the floor' => [HostSampling::MIN_INTERVAL_SECONDS, HostSampling::MIN_INTERVAL_SECONDS];
    }
}
