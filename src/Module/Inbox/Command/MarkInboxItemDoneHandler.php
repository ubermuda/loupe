<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxItemCloser;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class MarkInboxItemDoneHandler
{
    /** The done form has no field, so its errors name the item itself. */
    private const string ERROR_FIELD = 'item';

    public function __construct(
        private InboxItemCloser $closer,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(MarkInboxItemDoneCommand $command): InboxItem
    {
        $item = $command->item;
        if (InboxItemKind::Todo !== $item->kind) {
            throw new DomainErrors([self::ERROR_FIELD => 'inbox.done.error.not_a_todo']);
        }

        $this->closer->close($item, InboxItemState::Done, self::ERROR_FIELD, static function (InboxItem $item): void {
            $item->closeNote = null;
        });

        $this->auditor->record(
            'inbox.item_done',
            AuditOutcome::Success,
            ['itemId' => (string) $item->id, 'projectId' => (string) $item->project->id],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }
}
