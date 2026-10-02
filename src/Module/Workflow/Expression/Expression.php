<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Fact\Facts;

abstract readonly class Expression
{
    abstract public function evaluate(Facts $facts): bool;

    /**
     * The leaf whose waiting sentence explains why the expression is false. Null means that no leaf can
     * explain it: the expression is true, or it is a `not` over an empty `all`.
     */
    final public function firstFalseLeaf(Facts $facts): ?ConditionLeaf
    {
        return $this->leafAgainst($facts, true);
    }

    /** The first leaf that keeps the expression from the wanted value, or null when no leaf does. */
    abstract public function leafAgainst(Facts $facts, bool $wanted): ?ConditionLeaf;
}
