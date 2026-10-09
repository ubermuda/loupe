<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\BlockerHold;
use App\Module\Workflow\Contract\CardStateHints;
use App\Module\Workflow\Contract\StageDocumentTags;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(CardStateHints::class)]
final readonly class WorkflowCardStateHints implements CardStateHints
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private TemplateSource $templates,
    ) {
    }

    #[\Override]
    public function stageDocumentTags(Uuid $projectId): ?StageDocumentTags
    {
        $project = $this->projects->find($projectId);
        if (null === $project) {
            return null;
        }
        try {
            $template = $this->templates->forProject($projectId);
        } catch (TemplateMissing) {
            return null;
        }

        $byColumn = [];
        foreach ($this->workflowSlotLinks->findColumnsBySlot($project) as $slot => $column) {
            if (null !== $column) {
                $byColumn[$column->id->toRfc4122()] = $template->documentTagsFor($slot);
            }
        }

        return new StageDocumentTags($byColumn, $template->documentTagsFor(FactsBuilder::BACKLOG_SLOT), $template->documentTagsFor(null));
    }

    #[\Override]
    public function blockerHolds(array $cardIds): array
    {
        return array_map(
            static fn (array $row): BlockerHold => new BlockerHold($row['card_id'], new \DateTimeImmutable($row['since']), (int) $row['number'], $row['title']),
            $this->workflowRuleStates->findBlockerHolds($cardIds),
        );
    }
}
