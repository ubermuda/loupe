<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** Agent review does not hold the card back, or every open pull request head has a review that passed. */
final readonly class AgentReviewPassed implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'agent_review.passed';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.agent_review';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [AgentReviewFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $review = $facts->get(AgentReviewFacts::class);

        if (!$review->enabled || $review->epic) {
            return true;
        }

        return [] !== $review->heads && array_all($review->heads, static fn (ReviewedHead $head): bool => AgentReviewConclusion::Success === $head->conclusion);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.agent_review_passed' : 'workflow.waiting.agent_review_passed');
    }
}
