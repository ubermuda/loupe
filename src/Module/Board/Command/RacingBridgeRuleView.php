<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** One live bridge rule on a pull request that is behind, which races the sync of the app. */
final readonly class RacingBridgeRuleView
{
    public function __construct(
        public string $name,
        public \DateTimeImmutable $reportedAt,
        public string $bridgeId,
    ) {
    }
}
