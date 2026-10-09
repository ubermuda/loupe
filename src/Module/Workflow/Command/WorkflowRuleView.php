<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowRuleView
{
    /**
     * @param string                           $appliesToKey      a translation key for the slot or the column flag the rule watches
     * @param string                           $actionKey         a translation key that takes %target% and %kind%
     * @param ?string                          $targetKey         a translation key for the destination of a move
     * @param ?string                          $kind              the work kind of a request, or the write of a forge write
     * @param list<WorkflowConditionGroupView> $whenGroups        the conditions of `when`, grouped by source in first-seen order
     * @param list<WorkflowConditionGroupView> $untilGroups       the conditions of the `until` of a pause, grouped the same way
     * @param list<WorkflowConditionGroupView> $refillGroups      the conditions of the `refill` of a request with a limit, grouped the same way
     * @param list<string>                     $missingConditions the keys of the conditions this instance no longer has
     * @param ?string                          $missingAction     the name of the action this version does not know, or null
     */
    public function __construct(
        public string $id,
        public string $appliesToKey,
        public string $actionKey,
        public ?string $targetKey,
        public ?string $kind,
        public array $whenGroups,
        public array $untilGroups,
        public array $refillGroups,
        public array $missingConditions,
        public ?string $missingAction = null,
    ) {
    }
}
