<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\ColumnView;

final readonly class WorkflowColumnView
{
    /** @param ?string $slot the key of the slot linked to the column, or null */
    public function __construct(
        public ColumnView $column,
        public ?string $slot,
    ) {
    }
}
