<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class SentryCheckTest extends TestCase
{
    private const string DSN = 'https://key@o0.ingest.example/1';

    #[DataProvider('noDsn')]
    public function test_without_a_dsn_sentry_is_off(?string $dsn): void
    {
        $diagnostic = $this->check($dsn, 1.0, 1.0, false);

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('account.system_status.sentry.off', $diagnostic->detail);
    }

    /** @return iterable<string, array{?string}> */
    public static function noDsn(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'off string' => ['(null)'];
    }

    public function test_a_malformed_dsn_is_a_failure_that_shows_no_value(): void
    {
        $diagnostic = $this->check('not-a-dsn', 1.0, 1.0, true);

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame('account.system_status.sentry.malformed', $diagnostic->detail);
        self::assertSame([], $diagnostic->detailParameters);
    }

    public function test_profiling_without_excimer_is_a_warning(): void
    {
        $diagnostic = $this->check(self::DSN, 1.0, 0.5, false);

        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
        self::assertSame('account.system_status.sentry.no_excimer', $diagnostic->detail);
    }

    public function test_profiling_with_excimer_passes(): void
    {
        $diagnostic = $this->check(self::DSN, 1.0, 1.0, true);

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('account.system_status.sentry.configured', $diagnostic->detail);
    }

    /** @return iterable<string, array{float, float}> */
    public static function noProfiling(): iterable
    {
        yield 'no traces' => [0.0, 1.0];
        yield 'no profiles' => [1.0, 0.0];
    }

    #[DataProvider('noProfiling')]
    public function test_a_zero_rate_needs_no_excimer(float $tracesRate, float $profilesRate): void
    {
        $diagnostic = $this->check(self::DSN, $tracesRate, $profilesRate, false);

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('account.system_status.sentry.configured', $diagnostic->detail);
    }

    public function test_the_check_reports_in_the_application_catalogue_and_shows_no_dsn(): void
    {
        $diagnostic = $this->check(self::DSN, 1.0, 1.0, false);

        self::assertSame('sentry', $diagnostic->key);
        self::assertSame('messages', $diagnostic->domain);
        self::assertSame([], $diagnostic->detailParameters);
    }

    private function check(?string $dsn, float $tracesRate, float $profilesRate, bool $excimerLoaded): Diagnostic
    {
        return new SentryCheck($dsn, $tracesRate, $profilesRate, $excimerLoaded ? 'Core' : 'no_such_extension')();
    }
}
