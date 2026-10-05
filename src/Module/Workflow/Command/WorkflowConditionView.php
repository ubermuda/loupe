<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowConditionView
{
    /** @param string $params the parameters as "name: value" pairs, or '' when the condition takes none */
    public function __construct(
        public string $key,
        public bool $negated,
        public string $params,
    ) {
    }
}
