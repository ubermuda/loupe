<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/** The kinds of the open or claimed work requests of the card, each once. */
final readonly class WorkRequestFacts
{
    /** @param list<string> $activeKinds */
    public function __construct(
        public array $activeKinds,
    ) {
    }
}
