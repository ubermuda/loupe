<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\InteractiveRuns;

final readonly class CloseCardRunHandler
{
    public function __construct(
        private InteractiveRuns $interactiveRuns,
    ) {
    }

    /** Null when the session has no run on the card. A closed run comes back unchanged. */
    public function __invoke(CloseCardRunCommand $command): ?WorkerRun
    {
        $card = $command->card;

        return $this->interactiveRuns->close($card->project, $card->id ?? throw new \LogicException('A persisted card has an id.'), $command->sessionId);
    }
}
