<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentHandler;

/** Resolves the unresolved feedback of cards that reached a terminal column. */
final readonly class CardFeedbackResolver
{
    public const string TRIGGER = 'card_moved';

    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private ResolveSiteReviewCommentHandler $resolve,
    ) {
    }

    /** @param list<string> $cardIds */
    public function resolveFor(array $cardIds, CardReporter $actor): void
    {
        foreach ($this->cardSiteReviewComments->findUnresolvedForCards($cardIds) as $link) {
            ($this->resolve)(new ResolveSiteReviewCommentCommand($link->comment, self::TRIGGER, $actor->value));
        }
    }
}
