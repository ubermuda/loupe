<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Uid\Uuid;

/**
 * One state of one run, keyed by the id the bridge gave the run. The session
 * and the start come with a running report and an outcome. The end, the exit
 * code, the result flag, the failure reason and the output come with an outcome
 * alone.
 */
final readonly class ReportWorkerRunStateCommand
{
    public function __construct(
        public User $owner,
        public string $handle,
        public Uuid $runKey,
        public Uuid $bridgeId,
        public WorkerRunState $state,
        public \DateTimeImmutable $at,
        public Uuid $cardId,
        public int $cardNumber,
        public string $ruleName,
        public ?Uuid $sessionId = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $endedAt = null,
        public ?int $exitCode = null,
        public ?bool $hasResult = null,
        public ?string $failureReason = null,
        public ?string $output = null,
    ) {
    }
}
