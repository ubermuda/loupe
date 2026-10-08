<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** A verdict of the card has a delivery that no review settled yet. */
final readonly class VerdictUnsent implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'site_review.verdict_unsent';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [SiteReviewFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return [] !== $facts->get(SiteReviewFacts::class)->pendingDeliveryIds;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.site_review_verdict_unsent' : 'workflow.waiting.site_review_verdict_unsent');
    }
}
