<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

/** The work kinds of the open worker runs and the live work requests of the parent of the card, each kind once, empty with no parent. */
final readonly class ParentWorkFacts
{
    /** @param list<string> $activeKinds */
    public function __construct(
        public array $activeKinds,
    ) {
    }
}
