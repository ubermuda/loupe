<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowSlotView
{
    /**
     * @param string  $labelKey    a translation key
     * @param ?string $columnLabel the linked column's label, which may be a translation key, or null when the column is deleted
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public ?string $columnLabel,
    ) {
    }
}
