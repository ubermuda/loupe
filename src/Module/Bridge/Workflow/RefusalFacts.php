<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/** The reason the bridge gave for the work request of the card that settled last, when it refused it. */
final readonly class RefusalFacts
{
    public function __construct(
        public ?string $code,
    ) {
    }
}
