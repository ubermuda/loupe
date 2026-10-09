<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Messenger\SettleSiteReviewChecks;
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

    public const string EPIC_BRANCH_PATTERN_INVALID = 'board.automation.error.epic_branch_pattern_invalid';

    public const string AGENT_REVIEW_FAILING_SEVERITIES_INVALID = 'board.automation.error.agent_review_failing_severities_invalid';

    public function __invoke(SaveBoardAutomationSettingsCommand $command): void
    {
        $epicBranchPattern = trim($command->epicBranchPattern ?? '');
        if ('' !== $epicBranchPattern
            && (mb_strlen($epicBranchPattern) > BoardAutomationSettings::EPIC_BRANCH_PATTERN_MAX_LENGTH
                || 1 !== preg_match(BoardAutomationSettings::EPIC_BRANCH_PATTERN_RULE, $epicBranchPattern))) {
            throw new DomainErrors(['epicBranchPattern' => self::EPIC_BRANCH_PATTERN_INVALID]);
        }

        $failingSeverities = array_values(array_intersect(BoardAutomationSettings::AGENT_REVIEW_SEVERITIES, $command->agentReviewFailingSeverities));
        $unknown = array_filter($command->agentReviewFailingSeverities, static fn (mixed $severity): bool => !\in_array($severity, BoardAutomationSettings::AGENT_REVIEW_SEVERITIES, true));
        if ([] === $failingSeverities || [] !== $unknown) {
            throw new DomainErrors(['agentReviewFailingSeverities' => self::AGENT_REVIEW_FAILING_SEVERITIES_INVALID]);
        }

        $settings = $this->automation->settingsForUpdate($command->project);
        $wasEnabled = $settings->enabled;
        $wasSyncing = $settings->enabled && $settings->syncBehind;
        $wasOpeningEpics = $settings->openEpicPullRequests;
        $wasChecking = $settings->siteReviewCheck;
        $wasReviewing = $settings->agentReview;
        $settings->enabled = $command->enabled;
        $settings->commentOnFixQueued = $command->commentOnFixQueued;
        $settings->commentOnStaleApproval = $command->commentOnStaleApproval;
        $settings->syncBehind = $command->syncBehind;
        $settings->mergePullRequests = $command->mergePullRequests;
        $settings->changeBase = $command->changeBase;
        $settings->epicDraftSwitch = $command->epicDraftSwitch;
        $settings->closeEpicPullRequests = $command->closeEpicPullRequests;
        $settings->openEpicPullRequests = $command->openEpicPullRequests;
        $settings->postWidgetReviews = $command->postWidgetReviews;
        $settings->siteReviewCheck = $command->siteReviewCheck;
        $settings->agentReview = $command->agentReview;
        $settings->agentReviewFailingSeverities = $failingSeverities;
        $settings->epicBranchPattern = '' === $epicBranchPattern ? null : $epicBranchPattern;
        $this->em->flush();
        $this->events->dispatch(new BoardAutomationSettingsSaved(
            $command->project,
            !$wasEnabled && $command->enabled,
            !$wasOpeningEpics && $command->openEpicPullRequests,
            !$wasChecking && $command->siteReviewCheck,
            !$wasReviewing && $command->agentReview,
        ));

        if ($wasChecking && !$command->siteReviewCheck) {
            $this->bus->dispatch(new SettleSiteReviewChecks($command->project->id ?? throw new \LogicException('A stored project has an id.')));
        }

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
            'postWidgetReviews' => $command->postWidgetReviews,
            'siteReviewCheck' => $command->siteReviewCheck,
            'agentReview' => $command->agentReview,
            'agentReviewFailingSeverities' => implode(',', $failingSeverities),
            'epicBranchPattern' => $settings->epicBranchPattern,
        ]);
    }
}
