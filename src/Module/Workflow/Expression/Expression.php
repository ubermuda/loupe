<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Unreadable;

abstract readonly class Expression
{
    /** Call it only when unreadable() answers null. */
    abstract public function evaluate(Facts $facts): bool;

    /** The first source the expression cannot read. Null means that every leaf can evaluate. */
    abstract public function unreadable(Facts $facts): ?Unreadable;

    /**
     * The leaf whose waiting sentence explains why the expression is false. Null means that no leaf can
     * explain it: the expression is true, or it is a `not` over an empty `all`.
     */
    final public function firstFalseLeaf(Facts $facts): ?BlockingLeaf
    {
        return $this->leafAgainst($facts, true);
    }

    /** The first leaf that keeps the expression from the wanted value, or null when no leaf does. */
    abstract public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf;

    /** @return list<FactKey|class-string> the fact groups and facts classes the leaves read, each once, in first-seen order */
    abstract public function reads(): array;

    /** @return list<ConditionLeaf> every leaf, in template order */
    abstract public function leaves(): array;

    /**
     * @param list<Expression> $children
     *
     * @return list<FactKey|class-string>
     */
    protected static function readsOf(array $children): array
    {
        $keys = [];
        foreach ($children as $child) {
            foreach ($child->reads() as $key) {
                $keys[$key instanceof FactKey ? 'key:'.$key->value : 'class:'.$key] ??= $key;
            }
        }

        return array_values($keys);
    }

    /** @param list<Expression> $children */
    protected static function unreadableOf(array $children, Facts $facts): ?Unreadable
    {
        foreach ($children as $child) {
            $unreadable = $child->unreadable($facts);
            if (null !== $unreadable) {
                return $unreadable;
            }
        }

        return null;
    }
}
