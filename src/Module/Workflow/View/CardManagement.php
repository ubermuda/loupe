<?php

declare(strict_types=1);

namespace App\Module\Workflow\View;

/** Whether the workflow drives a card. A hold makes the card unmanaged. */
enum CardManagement: string
{
    case Managed = 'managed';
    case Unmanaged = 'unmanaged';
    case NoTemplate = 'no-template';

    public function translationKey(): string
    {
        return 'workflow.panel.management.'.str_replace('-', '_', $this->value);
    }
}
