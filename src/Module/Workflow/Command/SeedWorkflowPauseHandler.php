<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseView;

/** Pauses a card as the engine does after the last refused retry of the tech-design-write rule. Only the dev seed calls it. */
final readonly class SeedWorkflowPauseHandler
{
    public function __construct(
        private CardPauses $cardPauses,
    ) {
    }

    public function __invoke(SeedWorkflowPauseCommand $command): PauseView
    {
        return $this->cardPauses->pause($command->card, 'move-refused', 'tech-design-write', PauseKind::Retries)
            ?? throw new \LogicException('The card was already paused.');
    }
}
