<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricBucketTest extends TestCase
{
    /** @return iterable<string, array{MetricBucket, string, string}> */
    public static function times(): iterable
    {
        yield 'day' => [MetricBucket::Day, '2026-10-07 18:30:00 UTC', '2026-10-07 00:00:00'];
        yield 'day after a UTC conversion' => [MetricBucket::Day, '2026-10-08 01:30:00 +02:00', '2026-10-07 00:00:00'];
        yield 'week from a Wednesday' => [MetricBucket::Week, '2026-10-07 18:30:00 UTC', '2026-10-05 00:00:00'];
        yield 'week from a Monday' => [MetricBucket::Week, '2026-10-05 00:00:00 UTC', '2026-10-05 00:00:00'];
        yield 'week from a Sunday' => [MetricBucket::Week, '2026-10-11 23:59:59 UTC', '2026-10-05 00:00:00'];
        yield 'week across a month' => [MetricBucket::Week, '2026-10-01 12:00:00 UTC', '2026-09-28 00:00:00'];
        yield 'month' => [MetricBucket::Month, '2026-10-31 23:00:00 UTC', '2026-10-01 00:00:00'];
        yield 'month after a UTC conversion' => [MetricBucket::Month, '2026-11-01 00:30:00 +01:00', '2026-10-01 00:00:00'];
    }

    #[DataProvider('times')]
    public function test_a_bucket_starts_at_utc_midnight(MetricBucket $bucket, string $time, string $expected): void
    {
        $start = $bucket->startOf(new \DateTimeImmutable($time));

        self::assertSame($expected, $start->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $start->getTimezone()->getName());
    }

    public function test_a_range_starts_its_days_before_now(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 12:00:00 UTC');

        self::assertEquals(new \DateTimeImmutable('2026-09-05 12:00:00 UTC'), MetricRange::ThirtyDays->startFrom($now));
        self::assertEquals(new \DateTimeImmutable('2026-07-07 12:00:00 UTC'), MetricRange::NinetyDays->startFrom($now));
        self::assertNull(MetricRange::All->startFrom($now));
    }
}
