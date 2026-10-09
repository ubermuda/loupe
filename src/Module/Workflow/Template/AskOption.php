<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** One answer an ask offers: its label, and the actions that run when the owner picks it. */
final readonly class AskOption
{
    /** @param list<ActionCall> $actions */
    public function __construct(
        public string $label,
        public array $actions,
    ) {
    }
}
