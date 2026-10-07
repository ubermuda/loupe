<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use Symfony\Component\Uid\Uuid;

/**
 * The bridge queued a new run of the fix work kind. Dispatched after the
 * commit, once per run. The pull request comes from the context of the run's
 * work request, and is null when the run has none.
 */
final readonly class WorkerRunQueued
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $runId,
        public Uuid $cardId,
        public ?int $pullRequestNumber = null,
        public ?string $pullRequestUrl = null,
    ) {
    }
}
