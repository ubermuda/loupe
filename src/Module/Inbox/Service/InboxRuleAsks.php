<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxWorkflowAsk;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxWorkflowAskRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\RuleAsks;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Opens and withdraws the questions of a workflow rule. Every write takes the
 * project lock, as every writer of items does. The engine already holds the
 * lock of the card, so the card lock comes first and the project lock second.
 */
final readonly class InboxRuleAsks implements RuleAsks
{
    public const string CARD_HELD = 'The card is held.';
    public const string CARD_DELETED = 'The card is deleted.';

    public function __construct(
        private EntityManagerInterface $em,
        private InboxAvailability $inbox,
        private ProjectRepository $projects,
        private CardRepository $cards,
        private InboxItemRepository $inboxItems,
        private InboxWorkflowAskRepository $inboxWorkflowAsks,
        private InboxItemCloser $closer,
        private InboxSearchIndexer $searchIndexer,
        private InboxOpenCountPublisher $openCount,
        private InboxCardTileRefresher $cardTiles,
    ) {
    }

    #[\Override]
    public function isOn(Uuid $projectId): bool
    {
        return $this->inbox->isEnabled();
    }

    #[\Override]
    public function open(Uuid $projectId, Uuid $cardId, string $ruleId, string $question, array $options): Uuid
    {
        $project = $this->projects->find($projectId) ?? throw new \LogicException('The project of an ask exists.');
        $card = $this->cards->findOneByIdAndProjectId($cardId->toRfc4122(), $projectId->toRfc4122())
            ?? throw new \LogicException('The card of an ask exists in the project.');

        $item = $this->em->wrapInTransaction(function () use ($project, $card, $cardId, $ruleId, $question, $options): InboxItem {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $now = new \DateTimeImmutable();
            $item = new InboxItem(
                project: $project,
                number: $this->inboxItems->nextNumber($project),
                kind: InboxItemKind::Workflow,
                title: mb_substr($question, 0, InboxItem::MAX_TITLE_LENGTH),
                blocking: true,
                options: array_values($options),
                createdAt: $now,
                searchLanguage: $project->searchLanguage,
            );
            $item->cards->add(new InboxItemCard($item, $card, $now));
            $this->em->persist($item);

            $ask = new InboxAsk(project: $project, sessionId: null, bridgeId: null, createdAt: $now, origin: InboxAskOrigin::Loupe);
            $ask->items->add(new InboxAskItem($ask, $item, $now));
            $this->em->persist($ask);

            $this->em->persist(new InboxWorkflowAsk($item, $cardId, $ruleId, $now));
            $this->em->flush();
            $this->searchIndexer->index($item);

            return $item;
        });

        $this->openCount->countChanged($project);
        $this->cardTiles->refresh($item);

        return $item->id ?? throw new \LogicException('A stored item has an id.');
    }

    #[\Override]
    public function withdraw(Uuid $itemId, string $reason): void
    {
        $item = $this->inboxItems->find($itemId);
        if (null !== $item) {
            $this->withdrawItems([$item], $reason);
        }
    }

    /** Closes the open workflow items of a card, by card id so that a deleted card still counts. */
    public function withdrawForCard(Uuid $cardId, string $reason): void
    {
        $this->withdrawItems($this->inboxWorkflowAsks->findOpenItemsForCard($cardId), $reason);
    }

    /** @param list<InboxItem> $items */
    private function withdrawItems(array $items, string $reason): void
    {
        foreach ($items as $item) {
            $closed = $this->em->wrapInTransaction(function () use ($item, $reason): bool {
                $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
                if (InboxItemState::Open !== $this->inboxItems->lockedState($item)
                    || !$this->closer->close($item, InboxItemState::Withdrawn, $reason, new \DateTimeImmutable(), InboxEventType::ACTOR_AGENT)) {
                    return false;
                }
                $this->em->flush();

                return true;
            });

            if ($closed) {
                $this->openCount->countChanged($item->project);
                $this->cardTiles->refresh($item);
            }
        }
    }
}
