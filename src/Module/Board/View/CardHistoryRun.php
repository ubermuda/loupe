<?php

declare(strict_types=1);

namespace App\Module\Board\View;

/** The run that a `run-finished` history row records. The run id is null once the run is purged, so the row has no link. */
final readonly class CardHistoryRun
{
    public function __construct(
        public ?string $runId,
        public ?string $duration,
        public string $stateKey,
        public string $chipModifier,
        public bool $interactive,
        public bool $command,
    ) {
    }
}
