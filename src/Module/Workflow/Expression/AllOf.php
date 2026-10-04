<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Contract\Facts;

/** True when every child is true, so an empty list is true. */
final readonly class AllOf extends Expression
{
    /** @param list<Expression> $children */
    public function __construct(
        public array $children,
    ) {
    }

    #[\Override]
    public function evaluate(Facts $facts): bool
    {
        return array_all($this->children, fn ($child) => $child->evaluate($facts));
    }

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        if ($wanted) {
            foreach ($this->children as $child) {
                $leaf = $child->leafAgainst($facts, true);
                if (null !== $leaf) {
                    return $leaf;
                }
            }

            return null;
        }

        // Every child is true here, so the first child keeps the expression true. An empty list has no leaf.
        if (!$this->evaluate($facts) || [] === $this->children) {
            return null;
        }

        return $this->children[0]->leafAgainst($facts, false);
    }

    #[\Override]
    public function reads(): array
    {
        return self::readsOf($this->children);
    }

    #[\Override]
    public function leaves(): array
    {
        return array_merge(...array_map(static fn (Expression $child): array => $child->leaves(), $this->children));
    }
}
