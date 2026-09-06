<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Repository\CardRepository;

/**
 * The board cards in the account data export.
 *
 * It reads no feature flag. An export states what the account holds, and cards
 * written while the board was on stay the account's data after it goes off.
 */
final readonly class CardExporter implements UserDataExporterInterface
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'cards.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->cards->findByOwner($user) as $card) {
            yield [
                'id' => (string) $card->id,
                'project' => $card->project->name,
                'title' => $card->title,
                'body' => $card->body,
                'status' => $card->status->value,
                // The name, matching the board tools: the backing integer only
                // orders the board and means nothing to a reader.
                'priority' => $card->priority->label(),
                'type' => $card->type->value,
                'origin' => $card->origin->value,
                'position' => $card->position,
                'completedAt' => $card->completedAt?->format(\DateTimeInterface::ATOM),
                'createdAt' => $card->createdAt->format(\DateTimeInterface::ATOM),
                'updatedAt' => $card->updatedAt->format(\DateTimeInterface::ATOM),
                'pullRequests' => array_values(array_map(
                    static fn (CardPullRequest $link): array => [
                        'url' => $link->url,
                        'forge' => $link->forge->value,
                        'repository' => $link->repository,
                        'number' => $link->number,
                        'addedAt' => $link->addedAt->format(\DateTimeInterface::ATOM),
                    ],
                    $card->pullRequests->toArray(),
                )),
            ];
        }
    }
}
