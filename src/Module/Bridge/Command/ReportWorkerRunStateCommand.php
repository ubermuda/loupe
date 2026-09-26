<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use Symfony\Component\Uid\Uuid;

/**
 * One state of one run, keyed by the id the bridge gave the run. The session
 * and the start come with a running report and an outcome. The end, the exit
 * code, the result flag, the failure reason and the output come with an outcome
 * alone. The link to a resumed run and the card column come with the first
 * report, and the structured result and the usage come with an outcome.
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
        public ?string $resultStatus = null,
        /** @var array<string, mixed>|null */
        public ?array $resultFields = null,
        public ?Uuid $continues = null,
        public ?int $resumeIndex = null,
        public ?int $resumeCap = null,
        public ?string $cardColumn = null,
        public ?string $resumeSkipped = null,
        /** Null when the bridge sent no usage, which leaves the usage of the run unknown. */
        public ?WorkerRunUsageReport $usage = null,
    ) {
    }
}
