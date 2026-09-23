<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** Every run one bridge still holds, as the bridge sends it on each connect. */
final class ReportBridgeRunsRequest
{
    /** Far above what one bridge holds, and small enough to bound one request. */
    public const int MAX_RUNS = 1000;

    /**
     * @param list<BridgeRunInput>|null $runs
     */
    public function __construct(
        #[Assert\Count(max: self::MAX_RUNS)]
        #[Assert\NotNull]
        #[Assert\Valid]
        public ?array $runs = null,
    ) {
    }

    /**
     * @return array<string, WorkerRunState> the state of each run, keyed by the RFC 4122 run key
     */
    public function states(): array
    {
        $states = [];
        foreach ($this->runs ?? [] as $run) {
            $states[Uuid::fromString($run->runId ?? '')->toRfc4122()] = WorkerRunState::from($run->state ?? '');
        }

        return $states;
    }
}
