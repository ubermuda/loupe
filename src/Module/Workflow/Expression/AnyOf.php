<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Unreadable;

/** True when at least one child is true. The parser refuses an empty list. */
final readonly class AnyOf extends Expression
{
    /** @param non-empty-list<Expression> $children */
    public function __construct(
        public array $children,
    ) {
    }

    #[\Override]
    public function evaluate(Facts $facts): bool
    {
        return array_any($this->children, fn ($child) => $child->evaluate($facts));
    }

    #[\Override]
    public function unreadable(Facts $facts): ?Unreadable
    {
        return self::unreadableOf($this->children, $facts);
    }

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        if (!$wanted) {
            foreach ($this->children as $child) {
                $leaf = $child->leafAgainst($facts, false);
                if (null !== $leaf) {
                    return $leaf;
                }
            }

            return null;
        }

        // Every child is false here, so any child keeps the expression false. Report the first.
        if ($this->evaluate($facts)) {
            return null;
        }

        return $this->children[0]->leafAgainst($facts, true);
    }

    #[\Override]
    public function countAgainst(Facts $facts, bool $wanted): int
    {
        $counts = array_map(static fn (Expression $child): int => $child->countAgainst($facts, $wanted), $this->children);

        return $wanted ? min($counts) : self::sumOf($counts);
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

    #[\Override]
    public function missingKeys(): array
    {
        return array_merge(...array_map(static fn (Expression $child): array => $child->missingKeys(), $this->children));
    }
}
