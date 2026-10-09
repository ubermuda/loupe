<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The project asks for agent reviews, and the head of an open pull request has none. */
final readonly class AgentReviewDue implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'agent_review.due';
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

        return $review->enabled && !$review->epic && array_any($review->heads, static fn (ReviewedHead $head): bool => '' !== $head->headSha && null === $head->conclusion);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.agent_review_due' : 'workflow.waiting.agent_review_due');
    }
}
