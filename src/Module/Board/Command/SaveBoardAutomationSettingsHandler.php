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
        $wasEnabled = $settings->enabled;
        $wasSyncing = $settings->enabled && $settings->syncBehind;
        $settings->enabled = $command->enabled;
        $settings->commentOnFixQueued = $command->commentOnFixQueued;
        $settings->commentOnStaleApproval = $command->commentOnStaleApproval;
        $settings->syncBehind = $command->syncBehind;
        $settings->mergePullRequests = $command->mergePullRequests;
        $settings->changeBase = $command->changeBase;
        $settings->epicDraftSwitch = $command->epicDraftSwitch;
        $settings->closeEpicPullRequests = $command->closeEpicPullRequests;
        $settings->openEpicPullRequests = $command->openEpicPullRequests;
        $epicBranchPattern = trim($command->epicBranchPattern ?? '');
        $settings->epicBranchPattern = '' === $epicBranchPattern ? null : $epicBranchPattern;
        $this->em->flush();
        $this->events->dispatch(new BoardAutomationSettingsSaved($command->project, !$wasEnabled && $command->enabled));

        // A pull request that fell behind while the sync was off waits for no other trigger.
        if (!$wasSyncing && $command->enabled && $command->syncBehind) {
            $this->bus->dispatch(new SyncNextPullRequest($command->project->id ?? throw new \LogicException('A stored project has an id.')));
        }

        $this->auditor->record('board.automation_settings_saved', AuditOutcome::Success, [
            'projectId' => (string) $command->project->id,
            'enabled' => $command->enabled,
            'commentOnFixQueued' => $command->commentOnFixQueued,
            'commentOnStaleApproval' => $command->commentOnStaleApproval,
            'syncBehind' => $command->syncBehind,
            'mergePullRequests' => $command->mergePullRequests,
            'changeBase' => $command->changeBase,
            'epicDraftSwitch' => $command->epicDraftSwitch,
            'closeEpicPullRequests' => $command->closeEpicPullRequests,
            'openEpicPullRequests' => $command->openEpicPullRequests,
            'epicBranchPattern' => $settings->epicBranchPattern,
        ]);
    }
}
