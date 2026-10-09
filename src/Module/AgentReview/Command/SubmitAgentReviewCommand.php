<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\Board\Entity\Card;
use Symfony\Component\Uid\Uuid;

final readonly class SubmitAgentReviewCommand
{
    /**
     * @param ?Uuid                    $sessionId the session of the review worker, or null when the call names none
     * @param list<AgentReviewFinding> $findings
     */
    public function __construct(
        public Card $card,
        public ?Uuid $sessionId,
        public string $pullRequestUrl,
        public string $headSha,
        public string $summary,
        public array $findings,
    ) {
    }
}
