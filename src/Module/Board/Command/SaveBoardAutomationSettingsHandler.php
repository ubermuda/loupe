<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Service\BoardAutomation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final readonly class SaveBoardAutomationSettingsHandler
{
    public function __construct(
        private BoardAutomation $automation,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(SaveBoardAutomationSettingsCommand $command): void
    {
        $settings = $this->automation->settingsForUpdate($command->project);
        $wasEnabled = $settings->enabled;
        $settings->enabled = $command->enabled;
        $this->em->flush();
        $this->events->dispatch(new BoardAutomationSettingsSaved(
            $command->project,
            !$wasEnabled && $command->enabled,
        ));

        $this->auditor->record('board.automation_settings_saved', AuditOutcome::Success, [
            'projectId' => (string) $command->project->id,
            'enabled' => $command->enabled,
        ]);
    }
}
