<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Uid\Uuid;

final readonly class ReportBridgeRunsCommand
{
    /**
     * @param array<string, WorkerRunState> $runs the open state of each run the bridge holds, keyed by the RFC 4122 run key
     */
    public function __construct(
        public User $owner,
        public Uuid $bridgeId,
        public array $runs,
    ) {
    }
}
