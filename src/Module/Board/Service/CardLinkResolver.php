<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;

/**
 * Resolves a card write's link set to cards of the same project. Call it
 * before the handler opens its transaction, for the reason in DocumentLinkResolver.
 */
final readonly class CardLinkResolver
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /**
     * @param Card|null           $self   the card the links are written on; null while it is being created
     * @param list<CardLinkInput> $inputs
     *
     * @return list<array{Card, CardLinkKind}>
     */
    public function resolve(Project $project, ?Card $self, array $inputs): array
    {
        $resolved = [];
        $seen = [];
        foreach ($inputs as $input) {
            // Scoped to the project, so a card of another project reads as unknown.
            $card = $this->cards->findOneByIdAndProjectId(trim($input->cardId), (string) $project->id)
                ?? throw new DomainErrors(['relatedCards' => 'board.card.error.linked_card_unknown']);

            if (null !== $self && ($card === $self || (null !== $self->id && $self->id->equals($card->id)))) {
                throw new DomainErrors(['relatedCards' => 'board.card.error.linked_card_self']);
            }

            // Keyed by the resolved id, so two spellings of one id are one card.
            $key = (string) $card->id;
            if (isset($seen[$key])) {
                throw new DomainErrors(['relatedCards' => 'board.card.error.linked_card_twice']);
            }
            $seen[$key] = true;

            $resolved[] = [$card, $input->kind];
        }

        return $resolved;
    }
}
