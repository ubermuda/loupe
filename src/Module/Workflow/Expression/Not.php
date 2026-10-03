<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Fact\Facts;

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

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        return $this->inner->leafAgainst($facts, !$wanted);
    }

    #[\Override]
    public function reads(): array
    {
        return $this->inner->reads();
    }
}
