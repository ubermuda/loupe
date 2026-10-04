<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardHasChildren implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.has_children';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::Children];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->card->childCount > 0;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_has_children' : 'workflow.waiting.card_has_children');
    }
}
