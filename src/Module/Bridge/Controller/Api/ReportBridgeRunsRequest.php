<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\HeldRunKey;
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
     * @return array<string, WorkerRunState> the state of each run, keyed by HeldRunKey::of()
     */
    public function states(): array
    {
        $states = [];
        foreach ($this->runs ?? [] as $run) {
            $states[HeldRunKey::of(Uuid::fromString($run->projectId ?? ''), Uuid::fromString($run->runId ?? ''))] = WorkerRunState::from($run->state ?? '');
        }

        return $states;
    }
}
