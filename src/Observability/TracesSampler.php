<?php

declare(strict_types=1);

namespace App\Observability;

use Sentry\Tracing\SamplingContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * With no DSN the SDK would still build and profile every transaction, then
 * drop it at the transport. A zero rate stops that work at the start.
 */
final readonly class TracesSampler
{
    public function __construct(
        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn,

        #[Autowire('%env(float:SENTRY_TRACES_SAMPLE_RATE)%')]
        private float $sampleRate,
    ) {
    }

    public function __invoke(SamplingContext $context): float
    {
        if (null === $this->dsn || '' === $this->dsn) {
            return 0.0;
        }

        return match ($context->getParentSampled()) {
            true => 1.0,
            false => 0.0,
            null => $this->sampleRate,
        };
    }
}
