<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxItemCloser;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Declines a question or a to-do, with an optional note for the agent. */
final readonly class DeclineInboxItemHandler
{
    public function __construct(
        private InboxItemCloser $closer,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DeclineInboxItemCommand $command): InboxItem
    {
        $item = $command->item;
        $note = trim($command->note);

        $this->closer->respond($item, InboxItemState::Declined, 'closeNote', static function (InboxItem $item) use ($note): ?array {
            $item->selectedOptions = [];
            $item->answerText = null;
            $item->closeNote = '' === $note ? null : $note;

            return null;
        });

        $this->auditor->record(
            'inbox.item_declined',
            AuditOutcome::Success,
            ['itemId' => (string) $item->id, 'projectId' => (string) $item->project->id, 'hasNote' => '' !== $note],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }
}
