<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Template\RuleOrigin;

/** One kind of work the workflow can ask a bridge for. */
final readonly class WorkflowKindView
{
    /**
     * @param list<string> $rules  the ids of the rules that ask for it
     * @param list<string> $checks what the work needs from the project
     */
    public function __construct(
        public string $kind,
        public RuleOrigin $origin,
        public array $rules,
        public array $checks,
    ) {
    }
}
