<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

final readonly class CardTypeFacts
{
    public function __construct(
        public string $type,
    ) {
    }
}
