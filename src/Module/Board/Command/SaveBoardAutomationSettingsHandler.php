<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Messenger\SyncNextPullRequest;
use App\Module\Board\Repository\StuckPullRequestRepository;
use App\Module\Board\Service\BoardAutomation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
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
        private StuckPullRequestRepository $stuckPullRequests,
    ) {
    }

    public const string EPIC_BRANCH_PATTERN_INVALID = 'board.automation.error.epic_branch_pattern_invalid';

    public const string STUCK_DELAY_INVALID = 'board.automation.error.stuck_delay_invalid';

    public function __invoke(SaveBoardAutomationSettingsCommand $command): void
    {
        if ($command->stuckDelayMinutes < BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES || $command->stuckDelayMinutes > BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES) {
            throw new DomainErrors(['stuckDelayMinutes' => self::STUCK_DELAY_INVALID]);
        }

        $epicBranchPattern = trim($command->epicBranchPattern ?? '');
        if ('' !== $epicBranchPattern
            && (mb_strlen($epicBranchPattern) > BoardAutomationSettings::EPIC_BRANCH_PATTERN_MAX_LENGTH
                || 1 !== preg_match(BoardAutomationSettings::EPIC_BRANCH_PATTERN_RULE, $epicBranchPattern))) {
            throw new DomainErrors(['epicBranchPattern' => self::EPIC_BRANCH_PATTERN_INVALID]);
        }

        $settings = $this->automation->settingsForUpdate($command->project);
        $wasEnabled = $settings->enabled;
        $wasSyncing = $settings->enabled && $settings->syncBehind;
        $wasOpeningEpics = $settings->openEpicPullRequests;
        $settings->enabled = $command->enabled;
        $settings->commentOnFixQueued = $command->commentOnFixQueued;
        $settings->commentOnStaleApproval = $command->commentOnStaleApproval;
        $settings->syncBehind = $command->syncBehind;
        $settings->mergePullRequests = $command->mergePullRequests;
        $settings->changeBase = $command->changeBase;
        $settings->epicDraftSwitch = $command->epicDraftSwitch;
        $settings->closeEpicPullRequests = $command->closeEpicPullRequests;
        $settings->openEpicPullRequests = $command->openEpicPullRequests;
        $settings->epicBranchPattern = '' === $epicBranchPattern ? null : $epicBranchPattern;
        $delayChanged = $settings->stuckDelayMinutes !== $command->stuckDelayMinutes;
        $settings->stuckDelayMinutes = $command->stuckDelayMinutes;
        $this->em->flush();
        if ($delayChanged) {
            // A pull request announced under the old delay would never announce under a longer one.
            // The cards that link a ready one redraw now, because a longer delay can clear a Stuck mark.
            foreach ($this->stuckPullRequests->clearAnnouncements($command->project) as $cardId) {
                $this->events->dispatch(new CardChanged($command->project->id ?? throw new \LogicException('A stored project has an id.'), Uuid::fromString($cardId), CardChanged::UPDATED, false));
            }
        }
        $this->events->dispatch(new BoardAutomationSettingsSaved(
            $command->project,
            !$wasEnabled && $command->enabled,
            !$wasOpeningEpics && $command->openEpicPullRequests,
        ));

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
            'stuckDelayMinutes' => $settings->stuckDelayMinutes,
        ]);
    }
}
