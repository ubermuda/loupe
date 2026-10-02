<?php

declare(strict_types=1);

namespace App\Module\Workflow\View;

/** Where the card stands in the template, and the rule that moves it on. */
final readonly class CardWorkflowProgress
{
    public function __construct(
        public string $slot,
        public ?string $waiting,
        public ?string $nextAction,
        public ?CardWorkflowRefusal $lastRefusal,
    ) {
    }
}
