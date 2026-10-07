<?php

declare(strict_types=1);

namespace App\Module\Readiness\Workflow;

use App\Module\Readiness\Entity\DiscoveryRunState;

/** The latest discovery run of one card. Both are null when the card has no run. */
final readonly class DiscoveryFacts
{
    public function __construct(
        public ?string $runId,
        public ?DiscoveryRunState $state,
    ) {
    }
}
