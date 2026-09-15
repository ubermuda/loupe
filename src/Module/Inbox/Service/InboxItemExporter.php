<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Repository\InboxItemRepository;

/**
 * The inbox items in the account data export. It reads no feature flag, because
 * items written while the inbox was on stay the account's data after it goes off.
 */
final readonly class InboxItemExporter implements UserDataExporterInterface
{
    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'inbox_items.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->inboxItems->findByOwner($user) as $item) {
            yield [
                'id' => (string) $item->id,
                'project' => $item->project->name,
                'number' => $item->number,
                'kind' => $item->kind->value,
                'title' => $item->title,
                'body' => $item->body,
                'options' => $item->options,
                'multiple' => $item->multiple,
                'freeText' => $item->freeText,
                'blocking' => $item->blocking,
                'state' => $item->state->value,
                'selectedOptions' => $item->selectedOptions,
                'answerText' => $item->answerText,
                'closeNote' => $item->closeNote,
                'createdAt' => $item->createdAt->format(\DateTimeInterface::ATOM),
                'updatedAt' => $item->updatedAt->format(\DateTimeInterface::ATOM),
                'closedAt' => $item->closedAt?->format(\DateTimeInterface::ATOM),
                // Ids alone: the cards and documents are their own exporters' to state.
                'cards' => array_values(array_map(
                    static fn (InboxItemCard $link): string => (string) $link->card->id,
                    $item->cards->toArray(),
                )),
                'documents' => array_values(array_map(
                    static fn (InboxItemDocument $link): string => (string) $link->document->id,
                    $item->documents->toArray(),
                )),
            ];
        }
    }
}
