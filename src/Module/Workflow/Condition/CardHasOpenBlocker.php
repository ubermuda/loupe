<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardHasOpenBlocker implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.has_open_blocker';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::Blockers];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->card->hasOpenBlocker;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_has_open_blocker' : 'workflow.waiting.card_has_open_blocker');
    }
}
