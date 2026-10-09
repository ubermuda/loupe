<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\PauseKind;

/** The pause that a release ended. */
final readonly class ReleaseWorkflowPauseView
{
    public function __construct(
        public PauseKind $kind,
        public string $reason,
        public string $ruleId,
    ) {
    }
}
