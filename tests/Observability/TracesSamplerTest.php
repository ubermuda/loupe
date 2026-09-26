<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\TracesSampler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;

final class TracesSamplerTest extends TestCase
{
    /** @return iterable<string, array{?string, ?bool}> */
    public static function noDsn(): iterable
    {
        yield 'empty DSN' => ['', null];
        yield 'unset DSN' => [null, null];
        yield 'empty DSN under a sampled parent' => ['', true];
    }

    #[DataProvider('noDsn')]
    public function test_no_dsn_samples_nothing(?string $dsn, ?bool $parentSampled): void
    {
        $sampler = new TracesSampler($dsn, 1.0);

        self::assertSame(0.0, $sampler(self::context($parentSampled)));
    }

    public function test_a_dsn_samples_at_the_configured_rate(): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25);

        self::assertSame(0.25, $sampler(self::context(null)));
    }

    public function test_a_parent_decision_wins_over_the_rate(): void
    {
        $sampler = new TracesSampler('https://key@sentry.example/1', 0.25);

        self::assertSame(1.0, $sampler(self::context(true)));
        self::assertSame(0.0, $sampler(self::context(false)));
    }

    private static function context(?bool $parentSampled): SamplingContext
    {
        return SamplingContext::getDefault(new TransactionContext())->setParentSampled($parentSampled);
    }
}
