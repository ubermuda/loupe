<?php

declare(strict_types=1);

namespace App\Module\Workflow\Messenger;

final readonly class EvaluateCard
{
    public function __construct(
        public string $cardId,
    ) {
    }
}
