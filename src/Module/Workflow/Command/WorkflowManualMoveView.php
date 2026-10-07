<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

/** A manual move, with each end and who may make it as a translation key. */
final readonly class WorkflowManualMoveView
{
    public function __construct(
        public string $fromKey,
        public string $toKey,
        public string $byKey,
    ) {
    }
}
