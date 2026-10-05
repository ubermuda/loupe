<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowRuleView
{
    /**
     * @param string  $appliesToKey a translation key for the slot or the column flag the rule watches
     * @param string  $actionKey    a translation key that takes %target% and %kind%
     * @param ?string $targetKey    a translation key for the destination of a move
     * @param ?string $kind         the work kind of a request, or the write of a forge write
     * @param list<WorkflowConditionGroupView> $conditionGroups the conditions of `when` and `until`, grouped by source in first-seen order
     * @param list<string> $missingConditions the keys of the conditions this instance no longer has
     */
    public function __construct(
        public string $id,
        public string $appliesToKey,
        public string $actionKey,
        public ?string $targetKey,
        public ?string $kind,
        public array $conditionGroups,
        public array $missingConditions,
    ) {
    }
}
