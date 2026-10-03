<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use Symfony\Component\Uid\Uuid;

/**
 * A worker run of a session ended in a state that a resume continues, and no
 * person stopped it. Dispatched after the commit, with ids only.
 */
final readonly class ResumableWorkerRunEnded
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $bridgeId,
        public Uuid $sessionId,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
