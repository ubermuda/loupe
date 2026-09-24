<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;

/**
 * Resolves the parent a card write names to a card of the same project. Call
 * it before the handler opens its transaction, for the reason in DocumentLinkResolver.
 */
final readonly class CardParentResolver
{
    public const string UNKNOWN = 'board.card.error.parent_unknown';

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /** A blank id resolves to no parent. */
    public function resolve(Project $project, string $parentCardId): ?Card
    {
        $parentCardId = trim($parentCardId);
        if ('' === $parentCardId) {
            return null;
        }

        // Scoped to the project, so a card of another project reads as unknown.
        return $this->cards->findOneByIdAndProjectId($parentCardId, (string) $project->id)
            ?? throw new DomainErrors(['parent' => self::UNKNOWN]);
    }
}
