<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Condition\Condition;
use App\Module\Workflow\Fact\Facts;

final readonly class ConditionLeaf extends Expression
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public Condition $condition,
        public array $params,
    ) {
    }

    #[\Override]
    public function evaluate(Facts $facts): bool
    {
        return $this->condition->evaluate($facts, $this->params);
    }

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        return $this->evaluate($facts) === $wanted ? null : new BlockingLeaf($this, negated: !$wanted);
    }

    #[\Override]
    public function reads(): array
    {
        return $this->condition->reads($this->params);
    }

    #[\Override]
    public function leaves(): array
    {
        return [$this];
    }
}
