<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;

/** The head commit of one open pull request, and what the newest agent review of that commit concluded. */
final readonly class ReviewedHead
{
    public function __construct(
        public string $pullRequestId,
        public string $headSha,
        public ?AgentReviewConclusion $conclusion,
    ) {
    }
}
