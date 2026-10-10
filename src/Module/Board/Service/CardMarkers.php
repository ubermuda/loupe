<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Project\Entity\Project;
use App\Observability\RequestTimeline;

/** Reads the paused and unmanaged markers of a set of cards, in one query each. */
final readonly class CardMarkers
{
    public function __construct(
        private CardPauseRepository $cardPauses,
        private CardHoldRepository $cardHolds,
        private RequestTimeline $timeline,
    ) {
    }

    /**
     * @param list<Card>                $cards  cards of the project
     * @param ?array<string, CardPause> $paused the active pauses by card id, when the caller read them already
     *
     * @return array<string, non-empty-list<CardBadge>> card id => its markers in badge order; a card with none has no key
     */
    public function forCards(Project $project, array $cards, ?array $paused = null): array
    {
        if ([] === $cards) {
            return [];
        }

        return $this->timeline->span('board.card_markers', function () use ($project, $cards, $paused): array {
            $ids = array_map(static fn (Card $card): string => (string) $card->id, $cards);
            $paused ??= $this->cardPauses->findActiveForCardIds($ids);
            $held = array_flip($this->cardHolds->findCardIdsOfProject($project));

            $markers = [];
            foreach ($ids as $id) {
                $found = array_values(array_filter([
                    isset($paused[$id]) ? CardBadge::Paused : null,
                    isset($held[$id]) ? CardBadge::Unmanaged : null,
                ]));
                if ([] !== $found) {
                    $markers[$id] = $found;
                }
            }

            return $markers;
        }, data: ['cards' => \count($cards)]);
    }
}
