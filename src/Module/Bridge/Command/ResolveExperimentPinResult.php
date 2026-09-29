<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/**
 * The variant the card runs with. A null variant means the owner has no project
 * by that handle. $switchedFrom names the pinned variant that the rule no
 * longer offers.
 */
final readonly class ResolveExperimentPinResult
{
    public function __construct(
        public ?string $variant,
        public ?string $switchedFrom = null,
    ) {
    }
}
