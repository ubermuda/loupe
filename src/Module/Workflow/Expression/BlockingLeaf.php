<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use Symfony\Component\Translation\TranslatableMessage;

/** A leaf that keeps a rule false. Negated means the condition is true and the rule needs it false. */
final readonly class BlockingLeaf
{
    public function __construct(
        public ConditionLeaf $leaf,
        public bool $negated,
    ) {
    }

    public function waitingFor(): TranslatableMessage
    {
        return $this->leaf->condition->waitingFor($this->leaf->params, $this->negated);
    }
}
