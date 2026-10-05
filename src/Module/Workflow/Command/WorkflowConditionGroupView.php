<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowConditionGroupView
{
    /**
     * @param string                      $sourceKey  the translation key of the module whose data the conditions read
     * @param list<WorkflowConditionView> $conditions each condition once, in template order
     */
    public function __construct(
        public string $sourceKey,
        public array $conditions,
    ) {
    }
}
