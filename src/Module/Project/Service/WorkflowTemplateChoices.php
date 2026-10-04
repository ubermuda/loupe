<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

/** The workflow templates a person can pick for a new project. */
interface WorkflowTemplateChoices
{
    /** @return list<WorkflowTemplateChoice> */
    public function choices(): array;

    public function defaultKey(): string;
}
