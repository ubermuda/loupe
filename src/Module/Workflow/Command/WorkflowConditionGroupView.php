<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowConditionGroupView
{
    /**
     * @param string       $sourceKey     the translation key of the module whose data the conditions read
     * @param list<string> $conditionKeys each condition key once, in template order
     */
    public function __construct(
        public string $sourceKey,
        public array $conditionKeys,
    ) {
    }
}
