<?php

declare(strict_types=1);

namespace App\Module\Workflow\View;

final readonly class CardWorkflowPause
{
    public function __construct(
        public string $code,
        public string $kind,
        public string $reason,
        public string $release,
        public \DateTimeImmutable $since,
    ) {
    }
}
