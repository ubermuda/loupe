<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use Symfony\Component\Uid\Uuid;

/**
 * The bridge queued a new run to fix a pull request. Dispatched after the
 * commit, once per run, with ids and scalars only.
 */
final readonly class WorkerRunQueued
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $runId,
        public Uuid $cardId,
        public string $eventType,
        public ?string $forge,
        public ?string $repository,
        public ?int $pullRequestNumber,
        public ?string $headSha,
        public ?string $reason,
    ) {
    }
}
