<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ForgePullRequestWrites;
use App\Module\Forge\Service\PullRequestBranchUpdaters;
use App\Module\Forge\Service\PullRequestOpeners;
use App\Module\Forge\Service\PullRequestStateWriters;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\CardTypeCatalog;
use App\Module\Workflow\Contract\ChecksParameters;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\WorkflowRefusal;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Workflow\Template\ForgeWriteKind;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Writes to the pull request the rule acts on through the forge. A state write goes to each
 * pull request of the card. A write the project did not opt into, or that no writer of the forge supports, opens the fallback
 * work instead. A state write with no fallback then does nothing. The epic opening acts on an epic with no pull request:
 * it opens the pull request of the epic branch and links it to the epic, and it has no fallback.
 */
final readonly class ForgeWrite implements Action, ChecksParameters
{
    public const string OPEN_EPIC_OFF = WorkflowRefusal::OPEN_EPIC_OFF;

    public const string KEY = 'forge-write';
    private const array WRITES_WITHOUT_FALLBACK = ['draft', 'ready', 'close', 'open-epic'];

    public function __construct(
        private CardRepository $cards,
        private CardPullRequests $cardPullRequests,
        private BoardAutomation $boardAutomation,
        private ForgePullRequestWrites $forgePullRequestWrites,
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestBranchUpdaters $branchUpdaters,
        private PullRequestStateWriters $stateWriters,
        private PullRequestOpeners $pullRequestOpeners,
        private WorkRequestOpener $opener,
        private UpdateCardHandler $updateCard,
        private UrlGeneratorInterface $urlGenerator,
        private CardTypeCatalog $catalog,

        #[Autowire(param: 'app.workflow.merge_method')]
        private string $mergeMethod,
    ) {
    }

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.forge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('write', ParameterType::String, choices: array_map(static fn (ForgeWriteKind $kind): string => $kind->value, ForgeWriteKind::cases())),
            new Parameter('fallback', ParameterType::String, required: false),
        ];
    }

    #[\Override]
    public static function check(array $params): array
    {
        $needsFallback = !\in_array($params['write'] ?? null, self::WRITES_WITHOUT_FALLBACK, true);

        return $needsFallback && !\array_key_exists('fallback', $params) ? ['missing parameter "fallback"'] : [];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(countsTowardLimit: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.forge_write', 'workflow.panel.action.forge_write', panelParams: ['%write%' => (string) $params['write']], settingsDetail: (string) $params['write']);
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return isset($params['fallback']) ? (string) $params['fallback'] : null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $facts = $context->facts;
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        $write = ForgeWriteKind::tryFrom($context->string('write'))
            ?? throw new \LogicException(\sprintf('The rule "%s" names an unknown forge write.', $context->ruleId));
        $fallbackKind = $context->optionalString('fallback');
        $fallback = fn (): ActionOutcome => null === $fallbackKind ? ActionOutcome::done() : $this->opener->open($context, $fallbackKind, null);

        $pullRequests = $this->cardPullRequests->forCard($card);
        if (ForgeWriteKind::OpenEpic === $write) {
            return $this->openEpic($context->ruleId, $card, $pullRequests);
        }
        if (\in_array($write, [ForgeWriteKind::Draft, ForgeWriteKind::Ready, ForgeWriteKind::Close], true)) {
            if ([] === $pullRequests) {
                return ActionOutcome::done();
            }

            return self::optedIn($write, $this->boardAutomation->settingsOf($card->project))
                ? $this->writeStates($pullRequests, $write, $fallback)
                : $fallback();
        }

        $pullRequest = $this->cardPullRequests->subjectOf($pullRequests, $facts->pullRequest);
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
            ForgeWriteKind::OpenEpic => $settings->openEpicPullRequests,
            ForgeWriteKind::Comment => false,
        };
    }

    /**
     * Opens the pull request from the epic branch to the default branch, in the repository of the child pull request that merged into it last.
     *
     * @param list<ForgePullRequest> $pullRequests
     */
    private function openEpic(string $ruleId, Card $card, array $pullRequests): ActionOutcome
    {
        $settings = $this->boardAutomation->settingsOf($card->project);
        $epicBranch = $settings->epicBranchOf($card->number);
        if (!$this->catalog->forProject($card->project->requireId())->get($card->type)->children || null === $epicBranch) {
            return ActionOutcome::done();
        }
        // A refusal waits, and turning the write on re-arms it. A done rule never fires again.
        if (!self::optedIn(ForgeWriteKind::OpenEpic, $settings)) {
            return ActionOutcome::refused(self::OPEN_EPIC_OFF);
        }
        foreach ($pullRequests as $pullRequest) {
            if (PullRequestState::Open === $pullRequest->state && $epicBranch === $pullRequest->headBranch) {
                return ActionOutcome::done();
            }
        }

        $child = $this->cardPullRequests->lastChildMergedInto($card, $epicBranch);
        if (null === $child) {
            return ActionOutcome::refused('no-merged-child');
        }
        if (null === $child->defaultBranch) {
            return ActionOutcome::refused('no-default-branch');
        }
        $opener = $this->pullRequestOpeners->for($child->forge);
        if (null === $opener) {
            return ActionOutcome::done();
        }

        $cardUrl = $this->urlGenerator->generate('app_board_card', [
            'projectId' => (string) $card->project->id,
            'cardId' => (string) $card->id,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $body = \sprintf('The children of the epic %s merge into `%s`. This pull request carries them to `%s`.', $cardUrl, $epicBranch, $child->defaultBranch);
        try {
            $number = $opener->open($child, $epicBranch, $child->defaultBranch, $card->title, $body);
        } catch (PullRequestWriteFailed $e) {
            return ActionOutcome::refused($e->cause);
        }

        if ($this->cardPullRequests->links($card, $child->forge, $child->repository, $number)) {
            return ActionOutcome::done();
        }
        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: Actor::System,
                pullRequestUrls: [...$this->cardPullRequests->currentUrls($card), $opener->url($child, $number)],
                cause: CardEventCause::workflowRule($ruleId),
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused('link-refused');
        }

        return ActionOutcome::done();
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
