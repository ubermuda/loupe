<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryBrowserCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class SentryBrowserCheckTest extends TestCase
{
    /** @return iterable<string, array{?string}> */
    public static function noDsn(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'off string' => ['(null)'];
    }

    #[DataProvider('noDsn')]
    public function test_without_a_dsn_the_browser_sdk_is_off(?string $dsn): void
    {
        $diagnostic = new SentryBrowserCheck($dsn)();

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('account.system_status.sentry_browser.off', $diagnostic->detail);
    }

    public function test_a_malformed_dsn_is_a_failure_that_shows_no_value(): void
    {
        $diagnostic = new SentryBrowserCheck('not-a-dsn')();

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame('account.system_status.sentry_browser.malformed', $diagnostic->detail);
        self::assertSame([], $diagnostic->detailParameters);
    }

    public function test_a_valid_dsn_is_configured_and_shows_no_dsn(): void
    {
        $diagnostic = new SentryBrowserCheck('https://key@o0.ingest.example/1')();

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('account.system_status.sentry_browser.configured', $diagnostic->detail);
        self::assertSame('sentry_browser', $diagnostic->key);
        self::assertSame('messages', $diagnostic->domain);
        self::assertSame([], $diagnostic->detailParameters);
    }
}
