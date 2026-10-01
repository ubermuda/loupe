<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Messenger\SyncNextPullRequest;
use App\Module\Board\Service\BoardAutomation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final readonly class SaveBoardAutomationSettingsHandler
{
    public function __construct(
        private BoardAutomation $automation,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(SaveBoardAutomationSettingsCommand $command): void
    {
        $settings = $this->automation->settingsForUpdate($command->project);
        $wasSyncing = $settings->enabled && $settings->syncBehind;
        $settings->enabled = $command->enabled;
        $settings->mergeStrategy = $command->mergeStrategy;
        $settings->fixStrategy = $command->fixStrategy;
        $settings->loopLimit = $command->loopLimit;
        $settings->commentOnFixQueued = $command->commentOnFixQueued;
        $settings->syncBehind = $command->syncBehind;
        $this->em->flush();
        $this->events->dispatch(new BoardAutomationSettingsSaved($command->project));

        // A pull request that fell behind while the sync was off waits for no other trigger.
        if (!$wasSyncing && $command->enabled && $command->syncBehind) {
            $this->bus->dispatch(new SyncNextPullRequest($command->project->id ?? throw new \LogicException('A stored project has an id.')));
        }

        $this->auditor->record('board.automation_settings_saved', AuditOutcome::Success, [
            'projectId' => (string) $command->project->id,
            'enabled' => $command->enabled,
            'mergeStrategy' => $command->mergeStrategy->value,
            'fixStrategy' => $command->fixStrategy->value,
            'loopLimit' => $command->loopLimit,
            'commentOnFixQueued' => $command->commentOnFixQueued,
            'syncBehind' => $command->syncBehind,
        ]);
    }
}
