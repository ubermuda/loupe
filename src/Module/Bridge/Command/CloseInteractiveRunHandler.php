<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\InteractiveRuns;

final readonly class CloseInteractiveRunHandler
{
    public function __construct(
        private InteractiveRuns $interactiveRuns,
    ) {
    }

    /** Null when the id names no interactive run of the project. A closed run comes back unchanged. */
    public function __invoke(CloseInteractiveRunCommand $command): ?WorkerRun
    {
        return $this->interactiveRuns->closeById($command->project, $command->runId);
    }
}
