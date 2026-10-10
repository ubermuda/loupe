<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** The open or recently ended fix runs of one card that no pull request comment announces yet. */
final readonly class FixRunFacts
{
    /** @param list<string> $uncommentedRunIds sorted */
    public function __construct(
        public array $uncommentedRunIds,
    ) {
    }
}
