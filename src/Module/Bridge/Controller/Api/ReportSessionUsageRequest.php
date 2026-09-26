<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use Symfony\Component\Validator\Constraints as Assert;

/** The usage of each worker process of one session, in the order the processes started. */
final class ReportSessionUsageRequest
{
    public const int MAX_PROCESSES = 100;

    public function __construct(
        /** @var list<WorkerRunUsageInput>|null */
        #[Assert\Count(min: 1, max: self::MAX_PROCESSES)]
        #[Assert\NotNull]
        #[Assert\Valid]
        public ?array $processes = null,
    ) {
    }

    /** @return list<WorkerRunUsageReport> */
    public function processes(): array
    {
        return array_values(array_map(
            static fn (WorkerRunUsageInput $process): WorkerRunUsageReport => $process->report(),
            $this->processes ?? [],
        ));
    }
}
