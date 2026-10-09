<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** A stop on a card, as the workflow reads it. */
final readonly class PauseView
{
    public function __construct(
        public Uuid $id,
        public Uuid $projectId,
        public Uuid $cardId,
        public string $reason,
        public string $ruleId,
        public PauseKind $kind,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $releasedAt = null,
        public ?string $releaseReason = null,
    ) {
    }
}
