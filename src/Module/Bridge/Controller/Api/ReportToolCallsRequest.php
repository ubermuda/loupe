<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\WorkerRunTiming;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use Symfony\Component\Validator\Constraints as Assert;

/** One batch of the tool calls of a run. The last batch carries the timing of the run. */
final class ReportToolCallsRequest
{
    public const int MAX_CALLS = 500;

    public function __construct(
        /** @var list<WorkerRunToolCallInput>|null */
        #[Assert\Count(max: self::MAX_CALLS)]
        #[Assert\NotNull]
        #[Assert\Valid]
        public ?array $calls = null,

        #[Assert\Valid]
        public ?WorkerRunTimingInput $timing = null,
    ) {
    }

    /** @return list<WorkerRunToolCallReport> */
    public function calls(): array
    {
        return array_values(array_map(
            static fn (WorkerRunToolCallInput $call): WorkerRunToolCallReport => $call->report(),
            $this->calls ?? [],
        ));
    }

    public function timing(): ?WorkerRunTiming
    {
        return $this->timing?->timing();
    }
}
