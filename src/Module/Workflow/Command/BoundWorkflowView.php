<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class BoundWorkflowView
{
    /**
     * @param list<WorkflowSlotView>       $slots
     * @param list<WorkflowRuleView>       $rules          the rules of the template
     * @param list<WorkflowRuleView>       $appRules       the rules the app adds to every template
     * @param list<WorkflowManualMoveView> $manualMoves
     * @param list<int>                    $backoffMinutes
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $descriptionKey,
        public int $version,
        public \DateTimeImmutable $boundAt,
        public array $slots,
        public array $rules,
        public array $appRules,
        public array $manualMoves,
        public array $backoffMinutes,
        public int $workTimeoutMinutes,
    ) {
    }
}
