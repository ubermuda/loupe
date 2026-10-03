<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use Symfony\Component\Uid\Uuid;

/**
 * The bridge queued a new run of the fix work kind. Dispatched after the
 * commit, once per run, with ids only.
 */
final readonly class WorkerRunQueued
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $runId,
        public Uuid $cardId,
    ) {
    }
}
