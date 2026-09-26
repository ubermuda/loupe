<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use Symfony\Component\Uid\Uuid;

/** The usage of each process of one claude session, in the order the processes started. */
final readonly class ReportSessionUsageCommand
{
    /** @param list<WorkerRunUsageReport> $processes */
    public function __construct(
        public User $owner,
        public string $handle,
        public Uuid $sessionId,
        public array $processes,
    ) {
    }
}
