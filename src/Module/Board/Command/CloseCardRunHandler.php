<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Bridge\Command\RequestSessionUsageCollectionCommand;
use App\Module\Bridge\Command\RequestSessionUsageCollectionHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Psr\Log\LoggerInterface;

final readonly class CloseCardRunHandler
{
    public function __construct(
        private InteractiveRuns $interactiveRuns,
        private RequestSessionUsageCollectionHandler $collectUsage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Null when the session has no run on the card. A closed run comes back
     * unchanged, and each close asks again for its usage while it has none.
     */
    public function __invoke(CloseCardRunCommand $command): ?WorkerRun
    {
        $card = $command->card;

        $run = $this->interactiveRuns->close($card->project, $card->id ?? throw new \LogicException('A persisted card has an id.'), $command->sessionId);
        if (WorkerRunState::Closed !== $run?->state) {
            return $run;
        }

        // The close has committed, so a failed request must not fail it.
        try {
            ($this->collectUsage)(new RequestSessionUsageCollectionCommand($run));
        } catch (\Throwable $e) {
            $this->logger->warning('board.run_usage_request_failed', [
                'projectId' => (string) $card->project->id,
                'cardId' => (string) $card->id,
                'runId' => (string) $run->id,
                'exception' => $e,
            ]);
        }

        return $run;
    }
}
