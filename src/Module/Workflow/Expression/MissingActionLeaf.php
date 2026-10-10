<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;

/** The action of a rule that a stored template copy names and this version does not know. It is never readable, so the rule never fires. */
final readonly class MissingActionLeaf extends Expression
{
    public function __construct(
        public string $action,
    ) {
    }

    #[\Override]
    public function evaluate(Facts $facts): bool
    {
        return false;
    }

    #[\Override]
    public function unreadable(Facts $facts): Unreadable
    {
        return new Unreadable(UnreadableKind::MissingAction, $this->action);
    }

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        return null;
    }

    #[\Override]
    public function countAgainst(Facts $facts, bool $wanted): int
    {
        return 1;
    }

    #[\Override]
    public function reads(): array
    {
        return [];
    }

    #[\Override]
    public function leaves(): array
    {
        return [];
    }

    #[\Override]
    public function missingKeys(): array
    {
        return [];
    }
}
