<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** One rule a bridge reported dead, as the board banner shows it. */
final readonly class DeadBridgeRuleView
{
    /** @param list<string> $columns */
    public function __construct(
        public string $name,
        public array $columns,
        public string $reason,
        public \DateTimeImmutable $reportedAt,
        /** The whole bridge id, which an operator needs to clear the report. */
        public string $bridgeId,
    ) {
    }
}
