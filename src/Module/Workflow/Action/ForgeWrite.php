<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ForgePullRequestWrites;
use App\Module\Forge\Service\PullRequestBranchUpdaters;
use App\Module\Forge\Service\PullRequestStateWriters;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\ForgeWriteKind;
use App\Module\Workflow\Template\Rule;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes to the primary pull request of a card through the forge. A state write goes to each
 * pull request of the card. A write the project did not opt into, or that no writer of the forge supports, opens the fallback
 * work instead. A state write with no fallback then does nothing.
 */
final readonly class ForgeWrite implements Action
{
    public function __construct(
        private CardPullRequests $cardPullRequests,
        private BoardAutomation $boardAutomation,
        private ForgePullRequestWrites $forgePullRequestWrites,
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestBranchUpdaters $branchUpdaters,
        private PullRequestStateWriters $stateWriters,
        private WorkRequestOpener $opener,

        #[Autowire(param: 'app.workflow.merge_method')]
        private string $mergeMethod,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::ForgeWrite;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $write = ForgeWriteKind::tryFrom(ActionParams::string($rule, 'write'))
            ?? throw new \LogicException(\sprintf('The rule "%s" names an unknown forge write.', $rule->id));
        $fallbackKind = ActionParams::optionalString($rule, 'fallback');
        $fallback = fn (): ActionOutcome => null === $fallbackKind ? ActionOutcome::done() : $this->opener->open($rule, $card, $facts, $fallbackKind, null);

        $pullRequests = $this->cardPullRequests->forCard($card);
        if (\in_array($write, [ForgeWriteKind::Draft, ForgeWriteKind::Ready, ForgeWriteKind::Close], true)) {
            if ([] === $pullRequests) {
                return ActionOutcome::done();
            }

            return self::optedIn($write, $this->boardAutomation->settingsOf($card->project))
                ? $this->writeStates($pullRequests, $write, $fallback)
                : $fallback();
        }

        $pullRequest = $this->cardPullRequests->primary($pullRequests);
        if (null === $pullRequest) {
            return ActionOutcome::refused('no-pull-request');
        }
        if (ForgeWriteKind::Comment === $write) {
            return ActionOutcome::refused('unsupported-write');
        }
        if (!self::optedIn($write, $this->boardAutomation->settingsOf($card->project))) {
            return $fallback();
        }

        try {
            return match ($write) {
                ForgeWriteKind::Merge => $this->forgeWrite(fn () => $this->forgePullRequestWrites->merge($pullRequest, $this->mergeMethod), $fallback),
                ForgeWriteKind::ChangeBase => $this->changeBase($pullRequest, $fallback),
                ForgeWriteKind::UpdateBranch => $this->updateBranch($pullRequest, $fallback),
            };
        } catch (PullRequestWriteFailed|PullRequestSyncFailed $e) {
            return ActionOutcome::refused($e->cause);
        }
    }

    private static function optedIn(ForgeWriteKind $write, BoardAutomationSettings $settings): bool
    {
        return match ($write) {
            ForgeWriteKind::Merge => $settings->mergePullRequests,
            ForgeWriteKind::ChangeBase => $settings->changeBase,
            ForgeWriteKind::UpdateBranch => $settings->syncBehind,
            ForgeWriteKind::Draft, ForgeWriteKind::Ready => $settings->epicDraftSwitch,
            ForgeWriteKind::Close => $settings->closeEpicPullRequests,
            ForgeWriteKind::Comment => false,
        };
    }

    /**
     * ForgePullRequestWrites answers `no_writer` before it marks the pull request, so the fallback follows no write.
     *
     * @param \Closure(): void          $write
     * @param \Closure(): ActionOutcome $fallback
     */
    private function forgeWrite(\Closure $write, \Closure $fallback): ActionOutcome
    {
        try {
            $write();
        } catch (PullRequestWriteFailed $e) {
            if ('no_writer' === $e->cause) {
                return $fallback();
            }

            throw $e;
        }

        return ActionOutcome::done();
    }

    /** @param \Closure(): ActionOutcome $fallback */
    private function changeBase(ForgePullRequest $pullRequest, \Closure $fallback): ActionOutcome
    {
        $newBase = $this->parentBase($pullRequest);
        if (null === $newBase) {
            return ActionOutcome::refused('no-parent-base');
        }

        return $this->forgeWrite(fn () => $this->forgePullRequestWrites->changeBase($pullRequest, $newBase), $fallback);
    }

    /** The base of the merged pull request whose head is the base of this one. The last merge wins. */
    private function parentBase(ForgePullRequest $pullRequest): ?string
    {
        if (null === $pullRequest->baseBranch) {
            return null;
        }

        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        $parent = null;
        foreach ($this->forgePullRequests->findByHeadBranch($projectId, $pullRequest->forge, $pullRequest->repository, $pullRequest->baseBranch) as $candidate) {
            if ($candidate === $pullRequest || PullRequestState::Merged !== $candidate->state || null === $candidate->baseBranch) {
                continue;
            }
            if (null === $parent || $candidate->mergedAt > $parent->mergedAt) {
                $parent = $candidate;
            }
        }

        return $parent?->baseBranch;
    }

    /** @param \Closure(): ActionOutcome $fallback */
    private function updateBranch(ForgePullRequest $pullRequest, \Closure $fallback): ActionOutcome
    {
        $updater = $this->branchUpdaters->for($pullRequest->forge);
        if (null === $updater) {
            return $fallback();
        }
        if (null === $pullRequest->headSha) {
            return ActionOutcome::refused('no-head');
        }
        $updater->update($pullRequest, $pullRequest->headSha);

        return ActionOutcome::done();
    }

    /**
     * Writes every pull request that a writer supports, so a failure on one does not hold back the
     * others. The first failure refuses the action, and its retry writes each one again, which a writer allows.
     *
     * @param non-empty-list<ForgePullRequest> $pullRequests
     * @param \Closure(): ActionOutcome        $fallback
     */
    private function writeStates(array $pullRequests, ForgeWriteKind $write, \Closure $fallback): ActionOutcome
    {
        $failure = null;
        $written = false;
        foreach ($pullRequests as $pullRequest) {
            $writer = $this->stateWriters->for($pullRequest->forge);
            if (null === $writer) {
                continue;
            }
            $written = true;
            try {
                if (ForgeWriteKind::Close === $write) {
                    $writer->close($pullRequest);
                } else {
                    $writer->setDraft($pullRequest, ForgeWriteKind::Draft === $write);
                }
            } catch (PullRequestWriteFailed $e) {
                $failure ??= $e;
            }
        }

        if (!$written) {
            return $fallback();
        }

        return null === $failure ? ActionOutcome::done() : ActionOutcome::refused($failure->cause);
    }
}
