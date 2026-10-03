<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\CardProgress;
use App\Module\Board\Command\LaneDeckView;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\View\CardRunWarning;

/**
 * A short hash of what the card face and its list row show, and of the card's
 * column, so a page can tell a changed card from an unchanged one. The rank is
 * left out, because a renumber changes it on cards that no event announces.
 */
final readonly class CardDigest
{
    /** @param list<CardBadge> $badges */
    public function forCard(
        Card $card,
        int $pendingComments,
        int $documentCount,
        int $pullRequestCount,
        ?CardProgress $progress,
        ?CardRunWarning $runWarning,
        array $badges,
    ): string {
        return substr(sha1(json_encode([
            $card->number,
            $card->title,
            $card->type->value,
            $pendingComments,
            $pullRequestCount,
            $documentCount,
            (string) $card->column->id,
            $card->parent?->number,
            $card->parent?->title,
            $progress?->done,
            $progress?->total,
            $runWarning?->runId,
            $runWarning?->state->value,
            array_map(static fn (CardBadge $badge): string => $badge->value, $badges),
        ], \JSON_THROW_ON_ERROR)), 0, 12);
    }

    /** A short hash of what the head of an epic lane shows: the title, the progress and the Up next deck. */
    public function forLaneHead(Card $epic, ?CardProgress $progress, ?LaneDeckView $deck): string
    {
        return substr(sha1(json_encode([
            $epic->number,
            $epic->title,
            $progress?->done,
            $progress?->total,
            $deck?->count,
            array_map(static fn (Card $card): array => [(string) $card->id, $card->number, $card->title, $card->type->value], $deck->cards ?? []),
        ], \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
