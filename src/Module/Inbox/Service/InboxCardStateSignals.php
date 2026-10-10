<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardStateCode;
use App\Module\Board\Service\CardStateReason;
use App\Module\Board\Service\CardStateSignalsInterface;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;

/** An open inbox question that names a card means the card needs a person. */
final readonly class InboxCardStateSignals implements CardStateSignalsInterface
{
    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    #[\Override]
    public function signalsFor(Project $project, array $cards): array
    {
        $ids = array_map(static fn (Card $card): string => (string) $card->id, $cards);

        $signals = [];
        foreach ($this->inboxItems->findOpenAsksOfCards($project, $ids) as $row) {
            // The rows run oldest first, so the first reason of a card is the earliest.
            $signals[$row['card_id']] ??= [new CardStateReason(
                CardStateCode::OpenQuestion,
                ['%number%' => (int) $row['number'], '%title%' => $row['title']],
                new \DateTimeImmutable($row['created_at']),
            )];
        }

        return $signals;
    }
}
