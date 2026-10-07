<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Messenger\CollectSessionUsage;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CloseCardRunHandler
{
    public function __construct(
        private InteractiveRuns $interactiveRuns,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Null when the session has no run on the card. A closed run comes back
     * unchanged, and each close asks again for its usage while it has none.
     */
    public function __invoke(CloseCardRunCommand $command): ?WorkerRun
    {
        $card = $command->card;

        [$run, $closedNow] = $this->interactiveRuns->close($card->project, $card->id ?? throw new \LogicException('A persisted card has an id.'), $command->sessionId);
        // The close itself queued the request, so only a repeat close asks here.
        if (!$closedNow && WorkerRunState::Closed === $run?->state) {
            $this->bus->dispatch(new CollectSessionUsage((string) $run->project->id, (string) $run->id));
        }

        return $run;
    }
}
