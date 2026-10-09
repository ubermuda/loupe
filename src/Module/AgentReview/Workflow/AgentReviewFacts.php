<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

/** What the agent review rules read about one card. */
final readonly class AgentReviewFacts
{
    /**
     * @param list<ReviewedHead> $heads    the open pull requests of the card that have a head commit
     * @param bool               $enabled  whether the project asks an agent to review pull requests
     * @param bool               $epic     whether the card type may have children, so its pull request needs no review
     * @param bool               $unposted whether a review of the card has no check on the forge yet
     */
    public function __construct(
        public array $heads,
        public bool $enabled,
        public bool $epic,
        public bool $unposted,
    ) {
    }
}
