<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestList;
use Symfony\Component\Translation\TranslatableMessage;

/** At least one pull request is linked to the card, whatever its state. */
final readonly class PullRequestLinked implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.pr.linked';
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
        return [PullRequestList::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return [] !== $facts->pullRequests();
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_linked' : 'workflow.waiting.pr_linked');
    }
}
