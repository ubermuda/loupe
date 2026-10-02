<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Fact\Facts;

abstract readonly class Expression
{
    abstract public function evaluate(Facts $facts): bool;

    /** The leaf whose waiting sentence explains why the expression is false, or null when it is true. */
    final public function firstFalseLeaf(Facts $facts): ?ConditionLeaf
    {
        return $this->leafAgainst($facts, true);
    }

    /** The first leaf that keeps the expression from the wanted value, or null when it has that value. */
    abstract public function leafAgainst(Facts $facts, bool $wanted): ?ConditionLeaf;
}
