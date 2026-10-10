<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Unreadable;

final readonly class Not extends Expression
{
    public function __construct(
        public Expression $inner,
    ) {
    }

    #[\Override]
    public function evaluate(Facts $facts): bool
    {
        return !$this->inner->evaluate($facts);
    }

    /** The inner answer, so a negation never turns an unreadable leaf into true. */
    #[\Override]
    public function unreadable(Facts $facts): ?Unreadable
    {
        return $this->inner->unreadable($facts);
    }

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        return $this->inner->leafAgainst($facts, !$wanted);
    }

    #[\Override]
    public function countAgainst(Facts $facts, bool $wanted): int
    {
        return $this->inner->countAgainst($facts, !$wanted);
    }

    #[\Override]
    public function reads(): array
    {
        return $this->inner->reads();
    }

    #[\Override]
    public function leaves(): array
    {
        return $this->inner->leaves();
    }

    #[\Override]
    public function missingKeys(): array
    {
        return $this->inner->missingKeys();
    }
}
