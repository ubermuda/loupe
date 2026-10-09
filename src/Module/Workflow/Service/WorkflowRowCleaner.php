<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\WorkflowRowCleanup;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WorkflowRowCleanup::class)]
final readonly class WorkflowRowCleaner implements WorkflowRowCleanup
{
    public function __construct(
        private WorkflowRuleStateRepository $workflowRuleStates,
        private WorkflowPendingBaselineRepository $workflowPendingBaselines,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
    ) {
    }

    #[\Override]
    public function forgetCard(Uuid $cardId): void
    {
        $this->workflowRuleStates->deleteForCard($cardId);
        $this->workflowPendingBaselines->consume($cardId);
    }

    #[\Override]
    public function forgetColumn(Uuid $columnId): void
    {
        $this->workflowSlotLinks->clearColumn($columnId);
    }
}
