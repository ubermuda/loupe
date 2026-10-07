<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Board\Entity\BoardColumn;

final readonly class WorkflowColumnView
{
    /** @param ?string $slot the key of the slot linked to the column, or null */
    public function __construct(
        public BoardColumn $column,
        public ?string $slot,
    ) {
    }
}
