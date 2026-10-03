<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\Facts;

/** A condition whose answer can change as time passes, with no new fact. */
interface TimedCondition extends Condition
{
    /**
     * The time after the facts' now when the answer changes on the same facts, or null when it does not.
     *
     * @param array<string, mixed> $params
     */
    public function turnsAt(Facts $facts, array $params): ?\DateTimeImmutable;
}
