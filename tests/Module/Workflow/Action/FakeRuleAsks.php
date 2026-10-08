<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Workflow\Contract\RuleAsks;
use Symfony\Component\Uid\Uuid;

/** Records the questions and the withdrawals of the code under test. */
final class FakeRuleAsks implements RuleAsks
{
    public bool $on = true;

    /** @var list<array{itemId: Uuid, projectId: Uuid, cardId: Uuid, ruleId: string, question: string, options: list<string>}> */
    public array $opened = [];

    /** @var list<array{itemId: Uuid, reason: string}> */
    public array $withdrawn = [];

    #[\Override]
    public function isOn(Uuid $projectId): bool
    {
        return $this->on;
    }

    #[\Override]
    public function open(Uuid $projectId, Uuid $cardId, string $ruleId, string $question, array $options): Uuid
    {
        $itemId = Uuid::v7();
        $this->opened[] = ['itemId' => $itemId, 'projectId' => $projectId, 'cardId' => $cardId, 'ruleId' => $ruleId, 'question' => $question, 'options' => $options];

        return $itemId;
    }

    #[\Override]
    public function withdraw(Uuid $itemId, string $reason): void
    {
        $this->withdrawn[] = ['itemId' => $itemId, 'reason' => $reason];
    }
}
