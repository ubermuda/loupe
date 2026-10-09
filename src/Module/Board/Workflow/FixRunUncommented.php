<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** An open or recent fix run of the card has no pull request comment yet. */
final readonly class FixRunUncommented implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.fix_run.uncommented';
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
        return [FixRunFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return [] !== $facts->get(FixRunFacts::class)->uncommentedRunIds;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_fix_run_uncommented' : 'workflow.waiting.card_fix_run_uncommented');
    }
}
