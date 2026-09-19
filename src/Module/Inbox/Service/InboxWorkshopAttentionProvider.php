<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopAttentionItem;
use App\Module\Project\Workshop\WorkshopAttentionProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(WorkshopAttentionProviderInterface::class)]
final readonly class InboxWorkshopAttentionProvider implements WorkshopAttentionProviderInterface
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxReviewRepository $inboxReviews,
        private InboxAvailability $inbox,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): array
    {
        if (!$this->inbox->isEnabled()) {
            return [];
        }
        $items = iterator_to_array($this->inboxItems->findPageForProject($project, InboxItemState::Open, null, null, null, null, 1, 6), false);
        $subjects = [];
        foreach ($this->inboxReviews->findForItems($items) as $review) {
            $subjects[(string) $review->item->id] = $review->targetKind->value;
        }
        $attention = [];
        foreach ($items as $item) {
            $attention[] = new WorkshopAttentionItem(
                $item->title,
                $this->urls->generate('app_project_inbox', ['id' => (string) $project->id, '_fragment' => 'inbox-item-'.$item->number]),
                $item->kind->value,
                $item->blocking,
                $subjects[(string) $item->id] ?? 'agent',
            );
        }

        return $attention;
    }
}
