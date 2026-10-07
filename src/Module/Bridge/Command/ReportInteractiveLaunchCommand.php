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
        /** The kind of the work request, or the name of the session when it runs no work request. */
        public string $workKind,
        public WorkerRunState $state,
        public \DateTimeImmutable $at,
        public ?string $failureReason = null,
        public ?Uuid $workRequestId = null,
        public ?string $ruleId = null,
        /** The four harness fields: a null keeps the value the run holds, because a value can arrive in a later report. */
        public ?string $harness = null,
        public ?string $account = null,
        public ?string $model = null,
        public ?string $harnessSessionId = null,
    ) {
    }
}
