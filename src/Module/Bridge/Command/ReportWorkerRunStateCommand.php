<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use App\Module\Bridge\ValueObject\WorkSubject;
use Symfony\Component\Uid\Uuid;

/**
 * One state of one run, keyed by the id the bridge gave the run. The session
 * and the start come with a running report and an outcome. The end, the exit
 * code, the result flag, the failure reason and the output come with an outcome
 * alone. The link to a resumed run comes with the first report, and the
 * structured result and the usage come with an outcome. The work fields are
 * null for a run of an old bridge rule.
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
        public WorkSubject $subject,
        public ?int $cardNumber,
        public ?Uuid $workRequestId = null,
        public ?string $workKind = null,
        public ?string $ruleId = null,
        public ?Uuid $sessionId = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $endedAt = null,
        public ?int $exitCode = null,
        public ?bool $hasResult = null,
        public ?string $failureReason = null,
        public ?string $output = null,
        public ?string $resultStatus = null,
        public ?WorkerRunReason $resultReason = null,
        /** @var array<string, mixed>|null */
        public ?array $resultFields = null,
        public ?Uuid $continues = null,
        public ?string $resumeSkipped = null,
        /** Null when the bridge sent no usage, which leaves the usage of the run unknown. */
        public ?WorkerRunUsageReport $usage = null,
        /** Null keeps the pool the run holds, because a bridge that predates worker pools sends none. */
        public ?string $workerPool = null,
        public ?string $experiment = null,
        public ?string $variant = null,
        public ?string $requestedModel = null,
        public ?string $switchedFrom = null,
        /** Stored when the report creates the run. A later report never changes it. */
        public WorkerRunKind $kind = WorkerRunKind::Worker,
        /** The four harness fields: a null keeps the value the run holds, because a value can arrive in a later report. */
        public ?string $harness = null,
        public ?string $account = null,
        public ?string $model = null,
        public ?string $harnessSessionId = null,
        /** Null keeps the peak the run holds. */
        public ?int $peakContextTokens = null,
    ) {
    }
}
