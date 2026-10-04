<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

/** A move a person may make, with each end as a translation key. */
final readonly class WorkflowManualMoveView
{
    public function __construct(
        public string $fromKey,
        public string $toKey,
    ) {
    }
}
