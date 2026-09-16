<?php

declare(strict_types=1);

namespace App\Module\Board\View;

use Symfony\Component\Uid\Uuid;

final readonly class ReportedRule
{
    /** @param list<string> $columns */
    public function __construct(
        public Uuid $bridgeId,
        public string $name,
        public string $event,
        public array $columns,
        public string $state,
        public ?string $reason,
        public \DateTimeImmutable $reportedAt,
    ) {
    }
}
