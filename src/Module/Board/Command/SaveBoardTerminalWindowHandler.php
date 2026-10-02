<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\BoardAutomation;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final readonly class SaveBoardTerminalWindowHandler
{
    public function __construct(
        private BoardAutomation $automation,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SaveBoardTerminalWindowCommand $command): void
    {
        $this->automation->settingsForUpdate($command->project)->terminalWindowDays = $command->terminalWindowDays;
        $this->em->flush();

        $this->auditor->record('board.terminal_window_saved', AuditOutcome::Success, [
            'projectId' => (string) $command->project->id,
            'terminalWindowDays' => $command->terminalWindowDays,
        ]);
    }
}
