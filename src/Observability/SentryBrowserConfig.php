<?php

declare(strict_types=1);

namespace App\Observability;

use App\Service\BuildIdentity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The options the layout hands to the Sentry browser SDK, or null to load no SDK. */
final class SentryBrowserConfig extends AbstractExtension
{
    public function __construct(
        #[Autowire('%env(default::SENTRY_BROWSER_DSN)%')]
        private readonly ?string $dsn,

        #[Autowire('%env(float:SENTRY_BROWSER_TRACES_SAMPLE_RATE)%')]
        private readonly float $tracesSampleRate,
        private readonly BuildIdentity $buildIdentity,

        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('sentry_browser', $this->sentryBrowser(...))];
    }

    /** @return array{dsn: string, tracesSampleRate: float, release: ?string, environment: string}|null */
    public function sentryBrowser(): ?array
    {
        if (null === $this->dsn || SentryDsnStatus::Valid !== SentryDsnStatus::of($this->dsn)) {
            return null;
        }

        return [
            'dsn' => $this->dsn,
            'tracesSampleRate' => $this->tracesSampleRate,
            'release' => $this->buildIdentity->version,
            'environment' => $this->environment,
        ];
    }
}
