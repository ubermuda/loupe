<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Uid\Uuid;

/** How one launch of an interactive session by a bridge went: running or not-started. */
final readonly class ReportInteractiveLaunchCommand
{
    public function __construct(
        public User $owner,
        public string $handle,
        public Uuid $sessionId,
        public Uuid $bridgeId,
        public Uuid $cardId,
        public int $cardNumber,
        public string $ruleName,
        public WorkerRunState $state,
        public \DateTimeImmutable $at,
        public ?string $failureReason = null,
    ) {
    }
}
