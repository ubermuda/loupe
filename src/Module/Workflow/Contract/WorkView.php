<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** A unit of work for a card, as the workflow reads it. */
final readonly class WorkView
{
    public function __construct(
        public Uuid $id,
        public string $ruleId,
        public string $kind,
        public WorkState $state,
        public ?string $reason,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $reopenedAt = null,
        public ?\DateTimeImmutable $settledAt = null,
    ) {
    }
}
