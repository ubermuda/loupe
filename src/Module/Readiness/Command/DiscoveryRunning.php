<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

/** Thrown when a discovery starts while the latest run of the project is still requested. */
final class DiscoveryRunning extends \DomainException
{
    public const string MESSAGE = 'readiness.discovery.error.running';

    public function __construct(
        public readonly int $cardNumber,
    ) {
        parent::__construct(\sprintf('Discovery already runs on card #%d.', $cardNumber));
    }
}
