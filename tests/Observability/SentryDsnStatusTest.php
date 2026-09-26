<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryDsnStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SentryDsnStatusTest extends TestCase
{
    /** @return iterable<string, array{?string, SentryDsnStatus}> */
    public static function dsns(): iterable
    {
        yield 'unset' => [null, SentryDsnStatus::Off];
        yield 'empty string' => ['', SentryDsnStatus::Off];
        foreach (['false', '(false)', 'empty', '(empty)', 'null', '(null)', 'NULL', 'False'] as $off) {
            yield $off => [$off, SentryDsnStatus::Off];
        }
        yield 'no scheme' => ['not-a-dsn', SentryDsnStatus::Malformed];
        yield 'no key' => ['https://o0.ingest.example/1', SentryDsnStatus::Malformed];
        yield 'wrong scheme' => ['ftp://key@o0.ingest.example/1', SentryDsnStatus::Malformed];
        yield 'blank' => [' ', SentryDsnStatus::Malformed];
        yield 'valid' => ['https://key@o0.ingest.example/1', SentryDsnStatus::Valid];
    }

    #[DataProvider('dsns')]
    public function test_it_reads_a_dsn_as_the_sdk_does(?string $dsn, SentryDsnStatus $expected): void
    {
        self::assertSame($expected, SentryDsnStatus::of($dsn));
    }
}
