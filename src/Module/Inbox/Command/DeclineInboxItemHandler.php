<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Inbox\Service\InboxItemCloser;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Declines a question or a to-do, with an optional note for the agent. A wait
 * item declines as a dismissal, which its watch remembers.
 */
final readonly class DeclineInboxItemHandler
{
    public const string WORKFLOW_NOT_DECLINABLE = 'inbox.item.error.workflow_not_declinable';

    public function __construct(
        private InboxItemCloser $closer,
        private Auditor $auditor,
        private CardWaitTrigger $cardWaits,
        private InboxCardWatchRepository $inboxCardWatches,
    ) {
    }

    public function __invoke(DeclineInboxItemCommand $command): InboxItem
    {
        $item = $command->item;
        if (InboxItemKind::Workflow === $item->kind) {
            throw new DomainErrors(['closeNote' => self::WORKFLOW_NOT_DECLINABLE]);
        }
        $note = trim($command->note);

        $dismissedCardId = null;
        $this->closer->respond($item, InboxItemState::Declined, 'closeNote', function (InboxItem $item) use ($note, &$dismissedCardId): ?array {
            $item->selectedOptions = [];
            $item->answerText = null;
            $item->closeNote = '' === $note ? null : $note;
            if (InboxItemKind::Wait === $item->kind) {
                $dismissedCardId = $this->dismiss($item);
            }

            return null;
        });
        $this->cardWaits->forReviewItems([$item]);
        if (null !== $dismissedCardId) {
            $this->cardWaits->forCards($item->project->id ?? throw new \LogicException('Project has no id.'), [$dismissedCardId]);
        }

        $this->auditor->record(
            'inbox.item_declined',
            AuditOutcome::Success,
            ['itemId' => (string) $item->id, 'projectId' => (string) $item->project->id, 'hasNote' => '' !== $note],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }

    /** Runs under the project lock of the decline. It returns the card of the watch. */
    private function dismiss(InboxItem $item): string
    {
        $watch = $this->inboxCardWatches->findOneForItem($item)
            ?? throw new \LogicException('A wait item has a card watch.');
        $now = new \DateTimeImmutable();
        $watch->dismissedAt = $now;
        $watch->closedAt = $now;
        foreach ($watch->waits as $wait) {
            if (null === $wait->endedAt) {
                $wait->endedAt = $now;
                $wait->endReason = InboxCardWaitEndReason::Dismissed;
            }
        }

        return (string) $watch->cardId;
    }
}
