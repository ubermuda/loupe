<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class BindWorkflowTemplateCommand
{
    /** @param array<string, Uuid> $slotColumns each slot key of the template, mapped to the id of a column of the project */
    public function __construct(
        public Project $project,
        public string $templateKey,
        public array $slotColumns,
    ) {
    }
}
