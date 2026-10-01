<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;

/**
 * Whether an approval waits for the card's blockers. An approved stage
 * document keeps its card in the column the stage starts from while a card
 * that blocks it sits in a column that is not terminal. Every read is fresh,
 * so a caller under the project lock sees what the last writer committed.
 */
final readonly class StageHold
{
    public function __construct(
        private CardRepository $cards,
        private CardDocumentRepository $cardDocuments,
        private LifecycleStages $stages,
        private BoardColumnRepository $boardColumns,
    ) {
    }

    /** @return list<Card> */
    public function openBlockers(Card $card): array
    {
        return $this->cards->findOpenBlockersOf($card);
    }

    /**
     * The stage an approved document of the card says it has passed, while
     * the card still sits in the column that stage starts from. It reads the
     * card's column, and the board's columns, onto the entities.
     *
     * @return array{from: string, to: string}|null
     */
    public function heldStage(Card $card): ?array
    {
        $this->cards->refreshColumn($card);
        // The column itself too: another request may have renamed it or made it terminal.
        $this->boardColumns->findForProjectFresh($card->project);
        // A finished card waits for nothing, even in a column that shares a stage slug.
        if ($card->column->terminal) {
            return null;
        }

        foreach ($this->cardDocuments->findApprovedTagNamesForCard($card) as $names) {
            $stage = $this->stages->forTagNames($names);
            if (null !== $stage && $stage['from'] === $card->column->slug) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * The open blockers that keep an approved card in its stage column, or an
     * empty list when the card is not held.
     *
     * @return list<Card>
     */
    public function heldBy(Card $card): array
    {
        return null === $this->heldStage($card) ? [] : $this->openBlockers($card);
    }
}
