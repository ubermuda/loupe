<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use Symfony\Component\Uid\Uuid;

final readonly class EvaluateWorkflowCardCommand
{
    public function __construct(
        public Uuid $cardId,
    ) {
    }
}
