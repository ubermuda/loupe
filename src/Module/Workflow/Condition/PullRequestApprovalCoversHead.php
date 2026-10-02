<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestApprovalCoversHead implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.approval_covers_head';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('min', ParameterType::Int)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::PullRequest];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $pullRequest = $facts->pullRequest;

        return null !== $pullRequest && $pullRequest->approvalsCoveringHead >= ParameterValue::int($params, 'min');
    }

    #[\Override]
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.pr_approval_covers_head', ['%min%' => (string) ParameterValue::int($params, 'min')]);
    }
}
