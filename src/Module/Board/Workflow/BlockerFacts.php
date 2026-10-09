<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

final readonly class BlockerFacts
{
    public function __construct(
        public bool $hasOpenBlocker,
    ) {
    }
}
