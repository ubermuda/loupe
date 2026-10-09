<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** An open pull request of the card carries no site review check, or one that is out of date. */
final readonly class CheckStale implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.site_review.check_stale';
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
        $site = $facts->get(SiteReviewFacts::class);
        foreach ($site->checks as $check) {
            if (null === $check->postedSha
                || $check->postedSha !== $check->headSha
                || $check->postedConclusion !== $check->wantedConclusion
                || $check->postedNoteCount !== $check->noteCount
                || $check->postedNotesDigest !== $check->notesDigest
                || ($site->checkOptedIn && null === $check->postedRunId)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.site_review_check_stale' : 'workflow.waiting.site_review_check_stale');
    }
}
