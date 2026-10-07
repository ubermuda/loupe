<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

/** The workflow of one project, as an agent reads it to learn what each kind of work needs. */
final readonly class WorkflowView
{
    /**
     * @param list<WorkflowColumnView> $columns in board order
     * @param list<WorkflowKindView>   $kinds   in the order of their first rule, template rules first
     */
    public function __construct(
        public string $templateKey,
        public int $templateVersion,
        public array $columns,
        public array $kinds,
    ) {
    }
}
