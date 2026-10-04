<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;

/** A condition that a stored template copy names and this instance no longer has. It is never readable. */
final readonly class MissingConditionLeaf extends Expression
{
    public function __construct(
        public string $key,
        public mixed $params,
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
        return new Unreadable(UnreadableKind::MissingCondition, $this->key);
    }

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        return null;
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
}
