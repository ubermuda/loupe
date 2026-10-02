<?php

declare(strict_types=1);

namespace App\Module\Workflow\View;

final readonly class CardWorkflowRefusal
{
    public function __construct(
        public string $code,
        public string $reason,
        public \DateTimeImmutable $at,
        public int $attempts,
    ) {
    }
}
