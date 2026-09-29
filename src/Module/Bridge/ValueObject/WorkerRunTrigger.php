<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The event that made the bridge queue a run. Only the event type is always known. */
final readonly class WorkerRunTrigger
{
    public const string FIX_REQUESTED = 'pull_request.fix_requested';

    public function __construct(
        public string $eventType,
        public ?string $forge = null,
        public ?string $repository = null,
        public ?int $pullRequestNumber = null,
        public ?string $headSha = null,
        public ?string $reason = null,
    ) {
    }
}
