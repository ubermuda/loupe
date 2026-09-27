<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\CardProgress;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\View\CardRunWarning;

/**
 * A short hash of what the card face and its list row show, and of where the
 * card sits, so a page can tell a changed card from an unchanged one.
 */
final readonly class CardDigest
{
    public function forCard(
        Card $card,
        int $pendingComments,
        int $documentCount,
        int $pullRequestCount,
        ?CardProgress $progress,
        ?CardRunWarning $runWarning,
    ): string {
        $warning = null !== $runWarning && $runWarning->appliesTo($card->column->slug) ? $runWarning : null;

        return substr(sha1(json_encode([
            $card->number,
            $card->title,
            $card->body,
            $card->type->value,
            $pendingComments,
            $pullRequestCount,
            $documentCount,
            (string) $card->column->id,
            $card->position,
            $card->parent?->number,
            $card->parent?->title,
            $progress?->done,
            $progress?->total,
            $warning?->runId,
            $warning?->state->value,
        ], \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
