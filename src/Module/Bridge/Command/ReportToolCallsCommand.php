<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkerRunTiming;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use Symfony\Component\Uid\Uuid;

/** One batch of the tool calls of the run the bridge gave this key. */
final readonly class ReportToolCallsCommand
{
    /** @param list<WorkerRunToolCallReport> $calls */
    public function __construct(
        public User $owner,
        public string $handle,
        public Uuid $runKey,
        public array $calls,
        public ?WorkerRunTiming $timing,
    ) {
    }
}
