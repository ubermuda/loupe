<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Repository\InboxItemRepository;

/**
 * The inbox items in the account data export. It reads no feature flag, because
 * items written while the inbox was on stay the account's data after it goes off.
 */
final readonly class InboxItemExporter implements UserDataExporterInterface
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxCardWatchRepository $inboxCardWatches,
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
        $items = $this->inboxItems->findByOwner($user);
        $waits = [];
        $waitItems = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemKind::Wait === $item->kind));
        foreach ($this->inboxCardWatches->findForItems($waitItems) as $watch) {
            $waits[(string) $watch->item->id] = $watch->waits->toArray();
        }

        foreach ($items as $item) {
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
                // Ids and reasons: the documents and runs are their own exporters' to state.
                'waits' => array_values(array_map(
                    static fn (InboxCardWait $wait): array => [
                        'trigger' => $wait->trigger->value,
                        'type' => $wait->type->value,
                        'reason' => $wait->reason->value,
                        'documentId' => null === $wait->documentId ? null : (string) $wait->documentId,
                        'versionNumber' => $wait->versionNumber,
                        'runId' => null === $wait->runId ? null : (string) $wait->runId,
                        'pullRequestId' => null === $wait->pullRequestId ? null : (string) $wait->pullRequestId,
                        'headSha' => $wait->headSha,
                        'startedAt' => $wait->startedAt->format(\DateTimeInterface::ATOM),
                        'endedAt' => $wait->endedAt?->format(\DateTimeInterface::ATOM),
                        'endReason' => $wait->endReason?->value,
                    ],
                    $waits[(string) $item->id] ?? [],
                )),
            ];
        }
    }
}
