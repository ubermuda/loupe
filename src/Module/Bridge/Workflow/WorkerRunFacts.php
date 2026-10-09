<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/** The work kinds of the open worker runs of the card, each once. */
final readonly class WorkerRunFacts
{
    /** @param list<string> $activeKinds */
    public function __construct(
        public array $activeKinds,
    ) {
    }
}
