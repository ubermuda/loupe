<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\CardSnapshot;

final readonly class SeedWorkflowPauseCommand
{
    public function __construct(
        public CardSnapshot $card,
    ) {
    }
}
