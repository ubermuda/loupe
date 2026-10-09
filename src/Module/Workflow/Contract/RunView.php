<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A worker run, as the workflow reads it. */
final readonly class RunView
{
    public function __construct(
        /** The value of the run state. */
        public string $state,
        /** Whether the run ended as a stop. */
        public bool $stop,
    ) {
    }
}
