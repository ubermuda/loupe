<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\RuleAsks;
use Symfony\Component\Uid\Uuid;

/** The port while no module implements it: the inbox is off, so a rule that asks pauses its card. */
final readonly class NullRuleAsks implements RuleAsks
{
    #[\Override]
    public function isOn(Uuid $projectId): bool
    {
        return false;
    }

    #[\Override]
    public function open(Uuid $projectId, Uuid $cardId, string $ruleId, string $question, array $options): Uuid
    {
        throw new \LogicException('No module implements RuleAsks, so the inbox is off and no question can open.');
    }

    #[\Override]
    public function withdraw(Uuid $itemId, string $reason): void
    {
    }
}
