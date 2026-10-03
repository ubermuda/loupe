<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** Reads the DSN format only. The page never calls Sentry to check that the DSN works. */
final readonly class SentryBrowserCheck implements DiagnosticInterface
{
    public function __construct(
        #[Autowire('%env(default::SENTRY_BROWSER_DSN)%')]
        private ?string $dsn,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 4;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        return match (SentryDsnStatus::of($this->dsn)) {
            SentryDsnStatus::Off => new Diagnostic('sentry_browser', DiagnosticState::Ok, 'account.system_status.sentry_browser.off'),
            SentryDsnStatus::Malformed => new Diagnostic('sentry_browser', DiagnosticState::Failed, 'account.system_status.sentry_browser.malformed'),
            SentryDsnStatus::Valid => new Diagnostic('sentry_browser', DiagnosticState::Ok, 'account.system_status.sentry_browser.configured'),
        };
    }
}
