<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Board\Workflow\BlockerFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardHasOpenBlocker implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.has_open_blocker';
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
        return [BlockerFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(BlockerFacts::class)->hasOpenBlocker;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_has_open_blocker' : 'workflow.waiting.card_has_open_blocker');
    }
}
