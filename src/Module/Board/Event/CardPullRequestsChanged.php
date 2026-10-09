<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use Symfony\Component\Uid\Uuid;

/**
 * A card gained or dropped a link to GitHub pull requests. It carries ids and
 * keys rather than the card, so it stays valid for a deleted card.
 */
final readonly class CardPullRequestsChanged
{
    /**
     * @param list<array{string, int}> $references the repository, lower-cased, and the number of each pull request that the card gained or dropped
     */
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
        public array $references,
    ) {
    }
}
