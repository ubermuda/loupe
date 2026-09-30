<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\StopLadder;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StopLadderTest extends TestCase
{
    public function test_the_flags_set_the_delays(): void
    {
        $ladder = new StopLadder(
            FeatureFlags::service([StopLadder::SIGTERM_FLAG => 5000, StopLadder::SIGKILL_FLAG => 1000]),
            7500,
            2500,
        );

        self::assertSame(5000, $ladder->sigtermAfterMs());
        self::assertSame(1000, $ladder->sigkillAfterMs());
    }

    public function test_absent_flags_read_the_defaults(): void
    {
        $ladder = new StopLadder(FeatureFlags::service(), 7500, 2500);

        self::assertSame(7500, $ladder->sigtermAfterMs());
        self::assertSame(2500, $ladder->sigkillAfterMs());
    }

    #[DataProvider('valuesBelowTheFloor')]
    public function test_a_value_below_the_floor_reads_the_default(int $value): void
    {
        $ladder = new StopLadder(
            FeatureFlags::service([StopLadder::SIGTERM_FLAG => $value, StopLadder::SIGKILL_FLAG => $value]),
            7500,
            2500,
        );

        self::assertSame(7500, $ladder->sigtermAfterMs());
        self::assertSame(2500, $ladder->sigkillAfterMs());
    }

    /** @return iterable<string, array{int}> */
    public static function valuesBelowTheFloor(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'one below the floor' => [StopLadder::MIN_MS - 1];
    }

    public function test_the_floor_is_allowed(): void
    {
        $ladder = new StopLadder(
            FeatureFlags::service([StopLadder::SIGTERM_FLAG => StopLadder::MIN_MS, StopLadder::SIGKILL_FLAG => StopLadder::MIN_MS]),
            7500,
            2500,
        );

        self::assertSame(100, $ladder->sigtermAfterMs());
        self::assertSame(100, $ladder->sigkillAfterMs());
    }
}
