<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\ActionOutcomeKind;
use App\Module\Workflow\Action\ActionParams;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\WorkRequestOpener;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\RuleAsks;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Event\CardPaused;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\FactFingerprint;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use App\Module\Workflow\Template\WorkFailurePolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Evaluates the rules of the workflow template on one card. A rule fires on the rising edge
 * of its condition, and again when a refused attempt is due or the facts it reads change.
 */
final readonly class Engine
{
    public const string NO_BRIDGE_TOOK_WORK = 'no-bridge-took-work';

    public const string REPAIR_FAILED = 'repair-failed';

    private const string RUN_RESUMED = 'run-resumed';
    private const string SUBJECT_CHANGED = 'subject-changed';
    private const string REFILLED = 'refilled';
    private const string NO_LONGER_HOLDS = 'no-longer-holds';
    private const string LEFT_SLOT = 'left-slot';
    private const string RULE_REMOVED = 'rule-removed';

    public function __construct(
        private EntityManagerInterface $em,
        private CardRepository $cards,
        private TemplateSource $templates,
        private FactsBuilder $factsBuilder,
        private FactFingerprint $fingerprint,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private CardHolds $cardHolds,
        private WorkflowAutomation $automation,
        private WorkflowPendingBaselineRepository $workflowPendingBaselines,
        private WorkRequestRepository $workRequests,
        private WorkerRunRepository $workerRuns,
        private CardEventRepository $cardEvents,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private CardPauseRepository $cardPauses,
        private PauseCardHandler $pauseCard,
        private ReleaseCardPauseHandler $releaseCardPause,
        private Actions $actions,
        private WorkRequestOpener $opener,
        private RuleSubject $ruleSubject,
        private RuleAsks $ruleAsks,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
    ) {
    }

    public function evaluate(Uuid $cardId, \DateTimeImmutable $now): void
    {
        $run = $this->em->wrapInTransaction(fn (): ?Evaluation => $this->evaluateLocked($cardId, $now));
        if (null === $run) {
            return;
        }
        if ($run->holdChanged) {
            $this->events->dispatch(new CardChanged(
                $run->card->project->id ?? throw new \LogicException('A persisted project has an id.'),
                $cardId,
                CardChanged::UPDATED,
                false,
            ));
        }
        if ($run->baselined) {
            $this->logger->info('workflow.card_baselined', ['cardId' => $cardId->toRfc4122()]);

            return;
        }

        foreach ($run->pauses as $pause) {
            $this->events->dispatch(new CardPaused(
                $pause->project->id ?? throw new \LogicException('A persisted project has an id.'),
                $cardId,
                $pause->reason,
                $pause->kind,
            ));
        }
        if ([] !== $run->fired) {
            $this->logger->info('workflow.card_evaluated', ['cardId' => $cardId->toRfc4122(), 'fired' => $run->fired]);
        }
    }

    private function evaluateLocked(Uuid $cardId, \DateTimeImmutable $now): ?Evaluation
    {
        // Advisory, because an outbox insert takes a key-share lock on the project row,
        // and a FOR UPDATE on that row would deadlock against a claim or a settle.
        $this->workflowRuleStates->lockCard($cardId);
        $card = $this->cards->find($cardId);
        // A held card is unmanaged: settling its requests would cancel or expire them.
        if (null === $card || $this->cardHolds->isHeld($card->project, $cardId)) {
            return null;
        }
        // The mark makes the first pass after the automation is on again quiet.
        if (!$this->automation->runsFor($card->project)) {
            $this->workflowPendingBaselines->markCards($card->project->id ?? throw new \LogicException('A persisted project has an id.'), [$cardId]);

            return null;
        }
        $baseline = $this->workflowPendingBaselines->consume($cardId);
        $this->cards->refreshColumn($card);
        $this->cards->refreshTypeAndParent($card);

        try {
            $template = $this->templates->forProject($card->project->id ?? throw new \LogicException('A persisted project has an id.'));
        } catch (TemplateMissing) {
            return null;
        }

        $run = new Evaluation($card, $template, $this->facts($card, $now), $this->workflowRuleStates->findForCard($card), $now);
        $this->logMissingProviders($run);
        if ($baseline) {
            $this->settleWorkRequests($run, $cardId, expire: false);
            // Before the baseline, which replaces the fingerprint a retries pause compares against.
            $this->stillPaused($run);
            $this->baseline($run);
            $this->withdrawRemovedAsks($run);
            $this->em->flush();

            return $run;
        }
        $this->settleWorkRequests($run, $cardId);
        // A pause ends the pass, so it is never released in the pass that made it.
        if (!$run->ended && (!$this->stillPaused($run) || $this->releasedByRule($run))) {
            // After the release, so a refusal that settled during a pause counts in the pass that ends it.
            $this->readSettledRequests($run);
            $this->readResumedRun($run);
            if (!$run->ended) {
                $this->runRules($run, $template->rules);
            }
        }
        $this->withdrawRemovedAsks($run);
        $this->em->flush();

        return $run;
    }

    /** Records the truth of each rule the card reads now, clears its retries, and resets the rules of other slots. Fires none. */
    private function baseline(Evaluation $run): void
    {
        $run->baselined = true;
        foreach ($run->template->rules as $rule) {
            if (!$run->applies($rule)) {
                $state = $run->states[$rule->id] ?? null;
                if (null !== $state) {
                    $this->write($run, $state, $this->forget(...));
                }
                continue;
            }
            if ($this->waits($run, $rule)) {
                continue;
            }
            $this->write($run, $this->state($run, $rule), function (WorkflowRuleState $state) use ($run, $rule): void {
                $bound = $this->ruleSubject->bind($rule, $run->facts);
                if (!$bound->truth) {
                    $this->withdrawAsk($state, self::NO_LONGER_HOLDS);
                }
                if ($this->refills($run, $rule, $state->subjectPullRequestId)) {
                    $state->fires = 0;
                }
                $state->truth = $bound->truth;
                $this->markBlockerHold($run, $rule, $state, $bound);
                $state->fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
                if ($bound->truth) {
                    // Another pull request gets its own request budget.
                    if ($bound->binds && null !== $state->subjectPullRequestId && null !== $bound->subject && !$state->subjectPullRequestId->equals($bound->subject)) {
                        $state->fires = 0;
                    }
                    $state->subjectPullRequestId = $bound->subject;
                }
                $state->attempts = 0;
                $state->dueAt = null;
                $state->lastRefusal = null;
                $state->lastRefusalAt = null;
                $state->workRequestId = null;
                $state->repaired = false;
            });
        }
    }

    /**
     * Reads how the last request of each rule settled. A done request clears the attempts. A refused one earns a retry after
     * the next delay of the onWorkFailed block, or pauses the card when its code earns none or the retries or delays are used up.
     * When they are used up, a template with a repair kind first opens one repair request. A template with no onWorkFailed block reads nothing.
     */
    private function readSettledRequests(Evaluation $run): void
    {
        $policy = $run->template->onWorkFailed;
        // A second pause is refused, so a refusal waits until the active pause ends.
        if (null === $policy || null !== $this->cardPauses->findActiveForCard($run->card)) {
            return;
        }
        foreach ($run->template->rules as $rule) {
            $state = $run->states[$rule->id] ?? null;
            if ($run->ended || null === $state || null === $state->workRequestId || !$run->applies($rule) || $this->waits($run, $rule)) {
                continue;
            }
            $request = $this->workRequests->find($state->workRequestId);
            $bound = $this->ruleSubject->bind($rule, $run->facts);
            if (null === $request || !$bound->truth) {
                continue;
            }
            // The repair carries the rule id of the failed rule, so its kind tells it apart.
            $repair = $request->kind === $policy->repairKind;
            if ($repair && \in_array($request->state, [WorkRequestState::Open, WorkRequestState::Claimed], true)) {
                $run->repairing[$rule->id] = true;
                continue;
            }
            // The rule now acts on another pull request, so runRule() starts that work and the old refusal counts for nothing.
            $stored = $state->subjectPullRequestId;
            if ($bound->binds && null !== $stored && null !== $bound->subject && !$stored->equals($bound->subject)) {
                continue;
            }
            if ($repair && WorkRequestState::Done === $request->state) {
                // The rule fires its last try in this pass. The cleared id keeps the next pass from reading the repair again.
                $this->write($run, $state, static function (WorkflowRuleState $state) use ($run): void {
                    $state->dueAt = $run->now;
                    $state->workRequestId = null;
                });
                continue;
            }
            if (WorkRequestState::Done === $request->state) {
                $this->write($run, $state, static function (WorkflowRuleState $state): void {
                    $state->attempts = 0;
                    $state->dueAt = null;
                    $state->lastRefusal = null;
                    $state->lastRefusalAt = null;
                    $state->workRequestId = null;
                    $state->repaired = false;
                });
                continue;
            }
            // A refusal the engine counted already leaves lastRefusalAt at or after the settle.
            if (WorkRequestState::Refused !== $request->state || (null !== $state->lastRefusalAt && null !== $request->settledAt && $state->lastRefusalAt >= $request->settledAt)) {
                continue;
            }

            if ($repair) {
                $this->write($run, $state, function (WorkflowRuleState $state) use ($run, $rule): void {
                    $state->lastRefusal = self::REPAIR_FAILED;
                    $state->lastRefusalAt = $run->now;
                    $this->pause($run, CardPauseKind::Retries, self::REPAIR_FAILED, $rule->id);
                });
                continue;
            }

            $this->write($run, $state, fn (WorkflowRuleState $state) => $this->refuse($run, $rule, $state, $policy, $request->reason ?? 'failed', $bound));
        }
    }

    /**
     * Counts the end of a resumed run as a refusal, because a resumed run holds no work request that could settle as refused.
     * The release of the pause cleared the attempts, so the run earns the retries of a fresh budget.
     * The release left a refusal time with no refusal on the rule state. It counts the run once, because any later write clears it.
     */
    private function readResumedRun(Evaluation $run): void
    {
        $policy = $run->template->onWorkFailed;
        $cardId = $run->card->id ?? throw new \LogicException('A persisted card has an id.');
        $pause = $this->cardPauses->findLatestForCard($run->card);
        if (null === $policy || $run->ended || null === $pause || self::RUN_RESUMED !== $pause->releaseReason) {
            return;
        }
        $rule = $run->rule($pause->ruleId);
        $state = $run->states[$pause->ruleId] ?? null;
        if (null === $rule || null === $state || null !== $state->lastRefusal || null === $state->lastRefusalAt || !$run->applies($rule) || $this->waits($run, $rule)) {
            return;
        }
        $resumed = $this->workerRuns->findLatestContinuationOfCard($cardId, $pause->createdAt, $rule->id);
        $bound = $this->ruleSubject->bind($rule, $run->facts);
        if (null === $resumed || !$resumed->state->isStop() || !$bound->truth) {
            return;
        }

        $this->write($run, $state, fn (WorkflowRuleState $state) => $this->refuse($run, $rule, $state, $policy, $resumed->state->value, $bound));
    }

    /** Earns a retry after the next delay, or opens the repair, or pauses the card, as the failure block says. */
    private function refuse(Evaluation $run, Rule $rule, WorkflowRuleState $state, WorkFailurePolicy $policy, string $code, BoundRule $bound): void
    {
        $state->lastRefusal = $code;
        $state->lastRefusalAt = $run->now;
        if (!$policy->retries($code)) {
            $this->pause($run, CardPauseKind::WorkStopped, $code, $rule->id);

            return;
        }
        ++$state->attempts;
        // The failed run did no work of its own, so a retry never uses a request of the work limit.
        $state->fires = max(0, $state->fires - 1);
        $backoff = $policy->backoffMinutes[$state->attempts - 1] ?? null;
        if ($state->attempts > $policy->retries || null === $backoff) {
            $state->dueAt = null;
            if (null !== $policy->repairKind && !$state->repaired) {
                $outcome = $this->opener->open($rule, $run->card, $bound->facts, $policy->repairKind, null, $code);
                if (null !== $outcome->requestId) {
                    $state->workRequestId = $outcome->requestId;
                    $state->repaired = true;
                    $run->repairing[$rule->id] = true;
                    $this->logger->info('workflow.repair_requested', ['cardId' => $run->card->id?->toRfc4122(), 'ruleId' => $rule->id, 'code' => $code]);
                    // The rules after this one read the new request.
                    $run->facts = $this->facts($run->card, $run->now, $run->facts);

                    return;
                }
            }
            $this->pause($run, CardPauseKind::Retries, $code, $rule->id);

            return;
        }
        $state->dueAt = $run->now->add(new \DateInterval(\sprintf('PT%dM', $backoff)));
    }

    /**
     * Cancels the live requests of rules that no longer apply, and expires the open ones no bridge took in time.
     * An expiry pauses the card, unless the rule expires its work with no pause.
     * A baseline pauses nothing, so it leaves an overdue request to the next pass.
     */
    private function settleWorkRequests(Evaluation $run, Uuid $cardId, bool $expire = true): void
    {
        $withdrawn = false;
        foreach ($this->workRequests->findLiveForCard($cardId) as $request) {
            $requestId = $request->id ?? throw new \LogicException('A persisted work request has an id.');
            $rule = $run->rule($request->ruleId);
            if (null === $rule || !$run->applies($rule)) {
                $withdrawn = ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($requestId, WorkRequestState::Cancelled)) || $withdrawn;
                continue;
            }

            $deadline = ($request->reopenedAt ?? $request->createdAt)->add(new \DateInterval(\sprintf('PT%dM', $run->template->workTimeoutMinutes)));
            if ($expire && WorkRequestState::Open === $request->state && $deadline <= $run->now
                && ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($requestId, WorkRequestState::Expired))) {
                $withdrawn = true;
                // A repair always pauses, because nothing else ends its escalation.
                $state = $run->states[$rule->id] ?? null;
                $repair = null !== $state && $state->repaired && null !== $state->workRequestId && $state->workRequestId->equals($requestId);
                if ($repair || 'expire' !== ActionParams::optionalString($rule, 'onTimeout')) {
                    $this->pause($run, CardPauseKind::WorkTimeout, self::NO_BRIDGE_TOOK_WORK, $rule->id);
                }
            }
        }

        if ($withdrawn) {
            // The first build of the pass logged its failed sources already.
            $run->facts = $this->facts($run->card, $run->now, $run->facts);
        }
    }

    /** Releases the active pause when its condition is met. Answers whether the card stays paused. */
    private function stillPaused(Evaluation $run): bool
    {
        $pause = $this->cardPauses->findActiveForCard($run->card);
        if (null === $pause) {
            return false;
        }
        $code = $this->resumed($run, $pause) ? self::RUN_RESUMED : $this->releaseCode($run, $pause);
        if (null === $code) {
            $run->holdingPause = $pause;

            return true;
        }

        ($this->releaseCardPause)(new ReleaseCardPauseCommand($pause, $code));
        $rule = $run->rule($pause->ruleId);
        if (self::RUN_RESUMED === $code && null !== $rule) {
            $this->keepQuiet($run, $rule);
            $this->cardEvents->record($run->card, CardEventKind::PauseReleased, CardReporter::System, null, [
                'kind' => $pause->kind->value,
                'reason' => $pause->reason,
                'ruleId' => $pause->ruleId,
            ], $pause->releasedAt);
        }
        if (self::REFILLED === $code && null !== $rule) {
            $this->write($run, $this->state($run, $rule), static function (WorkflowRuleState $state): void {
                $state->fires = 0;
            });
        }
        if (\in_array($code, ['facts-changed', self::SUBJECT_CHANGED], true) && null !== $rule) {
            // A false truth makes the rule fire again at once, with a fresh backoff.
            // The subject and its count stay, so runRule() resets the count only when the new subject gets its request.
            $this->write($run, $this->state($run, $rule), static function (WorkflowRuleState $state): void {
                $state->truth = false;
                $state->attempts = 0;
                $state->dueAt = null;
                $state->workRequestId = null;
                $state->repaired = false;
            });
        }

        return false;
    }

    /**
     * Whether a worker ran or runs again on an earlier run since the pause began, which a person or a closed ask started.
     * A short run can end before the evaluation, so the run counts open or ended. A run that a person stopped does not count.
     */
    private function resumed(Evaluation $run, CardPause $pause): bool
    {
        $rule = $run->rule($pause->ruleId);
        if (null === $rule || !$run->applies($rule) || !\in_array($pause->kind, ReleaseWorkflowPauseCommand::RELEASABLE_KINDS, true)) {
            return false;
        }

        return null !== $this->workerRuns->findLatestContinuationOfCard($run->card->id ?? throw new \LogicException('A persisted card has an id.'), $pause->createdAt, $pause->ruleId);
    }

    /**
     * Leaves the rule true with a clean budget, so it fires no request next to the resumed worker.
     * A person's release makes the rule false instead, which fires it again at once.
     * The kept request and the refusal time with no refusal keep the old refusal from counting twice.
     */
    private function keepQuiet(Evaluation $run, Rule $rule): void
    {
        $this->write($run, $this->state($run, $rule), function (WorkflowRuleState $state) use ($run, $rule): void {
            $bound = $this->ruleSubject->bind($rule, $run->facts);
            $state->truth = $bound->truth;
            $state->fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
            $state->attempts = 0;
            $state->dueAt = null;
            $state->lastRefusal = null;
            $state->lastRefusalAt = $run->now;
            $state->repaired = false;
        });
    }

    /** Runs the release rules of a paused card. Answers whether they lifted the pause. */
    private function releasedByRule(Evaluation $run): bool
    {
        $this->runRules($run, array_filter($run->template->rules, static fn (Rule $rule): bool => ActionType::Release === $rule->then->type));

        $run->holdingPause = $this->cardPauses->findActiveForCard($run->card);

        return null === $run->holdingPause;
    }

    private function releaseCode(Evaluation $run, CardPause $pause): ?string
    {
        $rule = $run->rule($pause->ruleId);
        if (null === $rule) {
            return 'rule-removed';
        }
        $applies = $run->applies($rule);
        $bound = $this->ruleSubject->bind($rule, $run->facts);
        $stored = ($run->states[$rule->id] ?? null)?->subjectPullRequestId;
        if (CardPauseKind::Rule !== $pause->kind && $applies && $bound->binds && $bound->truth
            && null !== $stored && null !== $bound->subject && !$stored->equals($bound->subject)) {
            return self::SUBJECT_CHANGED;
        }

        return match ($pause->kind) {
            CardPauseKind::Rule => match (true) {
                ActionType::Ask === $rule->then->type => match (true) {
                    !$applies => 'facts-changed',
                    null !== $rule->when->unreadable($run->facts) => null,
                    !$bound->truth => 'facts-changed',
                    $this->ruleAsks->isOn($run->card->project->id ?? throw new \LogicException('A persisted project has an id.')) => 'inbox-on',
                    default => null,
                },
                null === $rule->then->until => 'rule-removed',
                null !== $rule->then->until->unreadable($run->facts) => null,
                $rule->then->until->evaluate($this->ruleSubject->paused($rule, $run->facts, $stored)) => 'until-met',
                default => null,
            },
            CardPauseKind::WorkLimit => match (true) {
                !$applies => 'left-slot',
                $this->refills($run, $rule, $stored) => self::REFILLED,
                default => null,
            },
            CardPauseKind::Retries, CardPauseKind::WorkTimeout, CardPauseKind::WorkStopped => match (true) {
                !$applies => 'facts-changed',
                null !== $rule->when->unreadable($run->facts) => null,
                !$bound->truth,
                $this->fingerprint->of($bound->facts, $rule->when->reads()) !== ($run->states[$rule->id] ?? null)?->fingerprint => 'facts-changed',
                default => null,
            },
        };
    }

    /** @param array<Rule> $rules */
    private function runRules(Evaluation $run, array $rules): void
    {
        foreach ($rules as $rule) {
            if (!$run->applies($rule)) {
                $state = $run->states[$rule->id] ?? null;
                if (null !== $state) {
                    $this->write($run, $state, $this->forget(...));
                }
                continue;
            }
            // Before state(), which persists a new state: a rule that waits writes none.
            if ($this->waits($run, $rule)) {
                continue;
            }

            $continue = true;
            $this->write($run, $this->state($run, $rule), function (WorkflowRuleState $state) use ($run, $rule, &$continue): void {
                $continue = $this->runRule($run, $rule, $state);
            });
            if (!$continue) {
                return;
            }
        }
    }

    /** Answers whether the rules after this one still run. */
    private function runRule(Evaluation $run, Rule $rule, WorkflowRuleState $state): bool
    {
        // The bound facts stay local to this rule: the rules after it read the facts as built.
        $bound = $this->ruleSubject->bind($rule, $run->facts);
        $fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
        if ($this->refills($run, $rule, $state->subjectPullRequestId)) {
            $state->fires = 0;
        }
        if (!$bound->truth) {
            // The reset forgets the repair, so its live request ends with it.
            $tracked = $state->repaired && null !== $state->workRequestId ? $this->workRequests->find($state->workRequestId) : null;
            if (null !== $tracked && $tracked->kind === $run->template->onWorkFailed?->repairKind
                && ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($state->workRequestId, WorkRequestState::Cancelled))) {
                // The rules after this one read the cancelled request.
                $run->facts = $this->facts($run->card, $run->now, $run->facts);
            }
            $this->withdrawAsk($state, self::NO_LONGER_HOLDS);
            $state->truth = false;
            $this->markBlockerHold($run, $rule, $state, $bound);
            $state->attempts = 0;
            $state->dueAt = null;
            $state->lastRefusal = null;
            $state->lastRefusalAt = null;
            $state->workRequestId = null;
            $state->repaired = false;
            // The subject stays, so a later true pass on another pull request starts a fresh budget.
            $state->fingerprint = $fingerprint;

            return true;
        }

        // A state with no subject adopts the bound one with no edge.
        $stored = $state->subjectPullRequestId;
        $newSubject = $bound->binds && null !== $stored && null !== $bound->subject && !$stored->equals($bound->subject);
        $fire = !isset($run->repairing[$rule->id])
            && ($newSubject || !$state->truth || ($state->attempts > 0 && ((null !== $state->dueAt && $state->dueAt <= $run->now) || $state->fingerprint !== $fingerprint)));
        // A release rule that turned true before its pause existed would otherwise wait for a new edge.
        if (!$fire && ActionType::Release === $rule->then->type) {
            $fire = null !== $run->holdingPause && $run->holdingPause->reason === ActionOutcome::code(ActionParams::string($rule, 'reason'));
        }
        $state->truth = true;
        $this->markBlockerHold($run, $rule, $state, $bound);
        $state->fingerprint = $fingerprint;
        // A live repair still serves the old subject.
        if (!isset($run->repairing[$rule->id])) {
            $state->subjectPullRequestId = $bound->subject;
        }
        if (!$fire) {
            return true;
        }
        // Another pull request gets its own request budget.
        $firesBefore = $state->fires;
        $repairedBefore = $state->repaired;
        if ($newSubject) {
            $state->fires = 0;
            $state->repaired = false;
        }

        $type = $rule->then->type;
        // A retry keeps its attempts while its request runs, so the count reaches the limit of the template.
        // The last try after a repair keeps them too, so its refusal pauses the card.
        $retrying = !$newSubject && (null !== $state->workRequestId || $state->repaired) && $state->attempts > 0;
        $outcome = $this->actions->get($type)->run($rule, $run->card, $bound->facts, $state);
        // The live request still serves the old subject, so the change waits until it settles.
        if ($newSubject && $outcome->alreadyLive) {
            $state->subjectPullRequestId = $stored;
            $state->fires = $firesBefore;
            $state->repaired = $repairedBefore;
        }
        $run->fired[] = ['rule' => $rule->id, 'outcome' => $outcome->kind->value, 'code' => $outcome->code];
        if (null !== $run->holdingPause?->releasedAt) {
            $run->holdingPause = null;
        }

        switch ($outcome->kind) {
            case ActionOutcomeKind::Done:
                if (!$retrying || (null === $outcome->requestId && !$outcome->alreadyLive)) {
                    $state->attempts = 0;
                }
                $state->dueAt = null;
                $state->lastRefusal = null;
                $state->lastRefusalAt = null;
                if (null !== $outcome->requestId) {
                    $state->workRequestId = $outcome->requestId;
                } elseif (!$outcome->alreadyLive) {
                    $state->workRequestId = null;
                }
                if ((ActionType::Request === $type || ActionType::ForgeWrite === $type) && !$outcome->alreadyLive) {
                    ++$state->fires;
                }
                // The rules after this one read the new request.
                if (ActionType::Request === $type && !$outcome->alreadyLive) {
                    $run->facts = $this->facts($run->card, $run->now, $run->facts);
                }

                // The move queues the next evaluation, and the rules after it would read the old slot.
                return ActionType::Move !== $type;
            case ActionOutcomeKind::Refused:
                $code = $outcome->code ?? throw new \LogicException('A refusal carries a code.');
                ++$state->attempts;
                $state->lastRefusal = $code;
                $state->lastRefusalAt = $run->now;
                $backoff = $run->template->backoffMinutes[$state->attempts - 1] ?? null;
                // The last try after a repair gets no further try.
                if (null !== $backoff && !$state->repaired) {
                    $state->dueAt = $run->now->add(new \DateInterval(\sprintf('PT%dM', $backoff)));

                    return true;
                }
                $state->dueAt = null;
                $this->pause($run, CardPauseKind::Retries, $code, $rule->id);

                return false;
            case ActionOutcomeKind::Pause:
                // The pause ends when its cause goes, and the rule must then fire again.
                if (ActionType::Ask === $type) {
                    $state->truth = false;
                }
                $this->pause(
                    $run,
                    $outcome->pauseKind ?? throw new \LogicException('A pause outcome carries a kind.'),
                    $outcome->code ?? throw new \LogicException('A pause outcome carries a code.'),
                    $rule->id,
                );

                return false;
        }
    }

    /** Logs each source that failed, unless it had already failed in the previous build of this evaluation. A source that is off is not an error. */
    private function facts(Card $card, \DateTimeImmutable $now, ?Facts $previous = null): Facts
    {
        $facts = $this->factsBuilder->build($card, $now);
        foreach ($facts->provided as $class => $provided) {
            if ($provided instanceof Unreadable && UnreadableKind::Failed === $provided->kind
                && UnreadableKind::Failed !== $previous?->unreadable($class)?->kind) {
                $this->logger->error('workflow.fact_source_failed', [
                    'cardId' => $card->id?->toRfc4122(),
                    'source' => $provided->source,
                    'exception' => $provided->cause,
                ]);
            }
        }

        return $facts;
    }

    /** Answers whether the refill of the rule holds on its stored subject. A rule with no refill, or no readable subject, never refills. */
    private function refills(Evaluation $run, Rule $rule, ?Uuid $stored): bool
    {
        $refill = $rule->then->refill;
        if (null === $refill || null !== $refill->unreadable($run->facts)) {
            return false;
        }
        $subject = $this->ruleSubject->stored($run->facts, $stored);

        return null !== $subject && $refill->evaluate($subject);
    }

    /** Stamps the first pass in which only an open blocker keeps a move rule false, and clears the stamp on the pass in which it does not. */
    private function markBlockerHold(Evaluation $run, Rule $rule, WorkflowRuleState $state, BoundRule $bound): void
    {
        $held = !$bound->truth && ActionType::Move === $rule->then->type && 1 === $rule->when->countAgainst($bound->facts, true);
        if ($held) {
            $blocking = $rule->when->firstFalseLeaf($bound->facts);
            $held = $blocking?->leaf->condition instanceof CardHasOpenBlocker && $blocking->negated;
        }
        $state->heldByBlockerSince = $held ? ($state->heldByBlockerSince ?? $run->now) : null;
    }

    /** Logs once per evaluation each facts class that a rule of the card reads and no provider gives. */
    private function logMissingProviders(Evaluation $run): void
    {
        $missing = [];
        foreach ($run->template->rules as $rule) {
            if (!$run->applies($rule)) {
                continue;
            }
            foreach ([...$rule->when->reads(), ...($rule->then->until?->reads() ?? []), ...($rule->then->refill?->reads() ?? [])] as $key) {
                if (\is_string($key) && !\array_key_exists($key, $run->facts->provided)) {
                    $missing[$key] = true;
                }
            }
        }
        foreach (array_keys($missing) as $class) {
            $this->logger->error('workflow.fact_provider_missing', ['cardId' => $run->card->id?->toRfc4122(), 'factsClass' => $class]);
        }
    }

    /**
     * A rule waits when it cannot read its facts. A pause whose until cannot be read could never release,
     * so its rule waits while it is true. A false one still records its edge.
     * A refill that cannot be read makes the rule wait too, like a when that cannot be read.
     */
    private function waits(Evaluation $run, Rule $rule): bool
    {
        return null !== $rule->when->unreadable($run->facts)
            || null !== $rule->then->refill?->unreadable($run->facts)
            || (null !== $rule->then->until?->unreadable($run->facts) && $this->ruleSubject->bind($rule, $run->facts)->truth);
    }

    /** Withdraws the ask of a state and clears its item id. An item that was answered is closed already, so the withdrawal changes nothing on it. */
    private function withdrawAsk(WorkflowRuleState $state, string $reason): void
    {
        if (null === $state->askItemId) {
            return;
        }
        $this->ruleAsks->withdraw($state->askItemId, $reason);
        $state->askItemId = null;
    }

    private function forget(WorkflowRuleState $state): void
    {
        $this->withdrawAsk($state, self::LEFT_SLOT);
        $state->reset();
    }

    /** Withdraws the ask of a rule that the template no longer has, because no pass reaches its state. */
    private function withdrawRemovedAsks(Evaluation $run): void
    {
        foreach ($run->states as $ruleId => $state) {
            if (null !== $state->askItemId && null === $run->rule($ruleId)) {
                $this->write($run, $state, fn (WorkflowRuleState $state) => $this->withdrawAsk($state, self::RULE_REMOVED));
            }
        }
    }

    private function state(Evaluation $run, Rule $rule): WorkflowRuleState
    {
        if (!isset($run->states[$rule->id])) {
            $state = new WorkflowRuleState($run->card, $run->card->project, $rule->id, $run->now);
            $this->em->persist($state);
            $run->states[$rule->id] = $state;
        }

        return $run->states[$rule->id];
    }

    /** @param \Closure(WorkflowRuleState): void $change */
    private function write(Evaluation $run, WorkflowRuleState $state, \Closure $change): void
    {
        $before = self::snapshot($state);
        $heldBefore = null !== $state->heldByBlockerSince;
        $change($state);
        if (self::snapshot($state) !== $before) {
            $state->updatedAt = $run->now;
        }
        if ($heldBefore !== (null !== $state->heldByBlockerSince)) {
            $run->holdChanged = true;
        }
    }

    /** @return list<mixed> */
    private static function snapshot(WorkflowRuleState $state): array
    {
        return [$state->truth, $state->attempts, $state->fires, $state->fingerprint, $state->dueAt?->format('U.u'), $state->lastRefusal, $state->lastRefusalAt?->format('U.u'), $state->subjectPullRequestId?->toRfc4122(), $state->workRequestId?->toRfc4122(), $state->repaired, $state->askItemId?->toRfc4122(), $state->heldByBlockerSince?->format('U.u')];
    }

    private function pause(Evaluation $run, CardPauseKind $kind, string $code, string $ruleId): void
    {
        $run->ended = true;
        $pause = ($this->pauseCard)(new PauseCardCommand($run->card, $code, $ruleId, $kind));
        if (null !== $pause) {
            $run->pauses[] = $pause;
        }
    }
}
