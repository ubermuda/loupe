<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Board\Entity\CardPauseKind;

/** The pause that a release ended. */
final readonly class ReleaseWorkflowPauseView
{
    public function __construct(
        public CardPauseKind $kind,
        public string $reason,
        public string $ruleId,
    ) {
    }
}
