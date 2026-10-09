<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestApprovalCoversHead implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.approval_covers_head';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.forge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('min', ParameterType::Int)];
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

        return null !== $pullRequest && $pullRequest->approvalsCoveringHead >= ParameterValue::int($params, 'min');
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_approval_covers_head' : 'workflow.waiting.pr_approval_covers_head', ['%min%' => (string) ParameterValue::int($params, 'min')]);
    }
}
