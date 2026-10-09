<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestDraft implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.draft';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.forge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [EngineFact::PullRequest];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $pullRequest = $facts->pullRequest;

        return null !== $pullRequest && PullRequestState::Open === $pullRequest->state && $pullRequest->draft;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_draft' : 'workflow.waiting.pr_draft');
    }
}
