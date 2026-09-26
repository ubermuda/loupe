<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** Reads the DSN format only. The page never calls Sentry to check that the DSN works. */
final readonly class SentryCheck implements DiagnosticInterface
{
    public function __construct(
        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn,

        #[Autowire('%env(float:SENTRY_TRACES_SAMPLE_RATE)%')]
        private float $tracesSampleRate,

        #[Autowire('%env(float:SENTRY_PROFILES_SAMPLE_RATE)%')]
        private float $profilesSampleRate,
        private string $profilerExtension = 'excimer',
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 5;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        // The SDK drops a malformed DSN with a debug log, so this row is the only visible sign.
        $status = SentryDsnStatus::of($this->dsn);
        if (SentryDsnStatus::Off === $status) {
            return new Diagnostic('sentry', DiagnosticState::Ok, 'account.system_status.sentry.off');
        }
        if (SentryDsnStatus::Malformed === $status) {
            return new Diagnostic('sentry', DiagnosticState::Failed, 'account.system_status.sentry.malformed');
        }

        if ($this->tracesSampleRate > 0.0 && $this->profilesSampleRate > 0.0 && !\extension_loaded($this->profilerExtension)) {
            return new Diagnostic('sentry', DiagnosticState::Warning, 'account.system_status.sentry.no_excimer');
        }

        return new Diagnostic('sentry', DiagnosticState::Ok, 'account.system_status.sentry.configured');
    }
}
