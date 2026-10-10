<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\WorkflowRuleStates;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WorkflowRuleStates::class)]
final readonly class WorkflowRuleRearmer implements WorkflowRuleStates
{
    public function __construct(
        private WorkflowRuleStateRepository $workflowRuleStates,
        private WorkflowPendingBaselineRepository $workflowPendingBaselines,
    ) {
    }

    #[\Override]
    public function baselineProject(Uuid $projectId): void
    {
        $this->workflowPendingBaselines->markProject($projectId);
    }

    #[\Override]
    public function rearmCards(array $cardIds, \DateTimeImmutable $now): void
    {
        $this->workflowRuleStates->resetForCards($cardIds, $now);
        $this->workflowPendingBaselines->unmarkCards($cardIds);
    }
}
