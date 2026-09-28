<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\BoardAutomation;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final readonly class SaveBoardAutomationSettingsHandler
{
    public function __construct(
        private BoardAutomation $automation,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SaveBoardAutomationSettingsCommand $command): void
    {
        $settings = $this->automation->settingsForUpdate($command->project);
        $settings->enabled = $command->enabled;
        $settings->mergeStrategy = $command->mergeStrategy;
        $settings->fixStrategy = $command->fixStrategy;
        $settings->loopLimit = $command->loopLimit;
        $this->em->flush();

        $this->auditor->record('board.automation_settings_saved', AuditOutcome::Success, [
            'projectId' => (string) $command->project->id,
            'enabled' => $command->enabled,
            'mergeStrategy' => $command->mergeStrategy->value,
            'fixStrategy' => $command->fixStrategy->value,
            'loopLimit' => $command->loopLimit,
        ]);
    }
}
