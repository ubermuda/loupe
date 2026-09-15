<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Repository\InboxAskRepository;

/**
 * The inbox asks in the account data export. A file of its own, because one item
 * can sit in several asks and nesting would repeat it.
 */
final readonly class InboxAskExporter implements UserDataExporterInterface
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'inbox_asks.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->inboxAsks->findByOwner($user) as $ask) {
            yield [
                'id' => (string) $ask->id,
                'project' => $ask->project->name,
                'sessionId' => (string) $ask->sessionId,
                'bridgeId' => null === $ask->bridgeId ? null : (string) $ask->bridgeId,
                'context' => $ask->context,
                'card' => null === $ask->card ? null : (string) $ask->card->id,
                'createdAt' => $ask->createdAt->format(\DateTimeInterface::ATOM),
                'closedAt' => $ask->closedAt?->format(\DateTimeInterface::ATOM),
                'items' => array_values(array_map(
                    static fn (InboxAskItem $membership): array => [
                        'item' => (string) $membership->item->id,
                        'addedAt' => $membership->addedAt->format(\DateTimeInterface::ATOM),
                        'readAt' => $membership->readAt?->format(\DateTimeInterface::ATOM),
                    ],
                    $ask->items->toArray(),
                )),
            ];
        }
    }
}
