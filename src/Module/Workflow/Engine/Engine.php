<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\ActionOutcomeKind;
use App\Module\Workflow\Action\ActionParams;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Contract\Facts;
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
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private CardPauseRepository $cardPauses,
        private PauseCardHandler $pauseCard,
        private ReleaseCardPauseHandler $releaseCardPause,
        private Actions $actions,
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
            $this->em->flush();

            return $run;
        }
        $this->settleWorkRequests($run, $cardId);
        // A pause ends the pass, so it is never released in the pass that made it.
        if (!$run->ended && (!$this->stillPaused($run) || $this->releasedByRule($run))) {
            $this->runRules($run, $template->rules);
        }
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
                    $this->write($run, $state, static fn (WorkflowRuleState $state) => $state->reset());
                }
                continue;
            }
            if (null !== $rule->when->unreadable($run->facts)) {
                continue;
            }
            $this->write($run, $this->state($run, $rule), function (WorkflowRuleState $state) use ($run, $rule): void {
                $state->truth = $rule->when->evaluate($run->facts);
                $state->fingerprint = $this->fingerprint->of($run->facts, $rule->when->reads());
                $state->attempts = 0;
                $state->dueAt = null;
                $state->lastRefusal = null;
                $state->lastRefusalAt = null;
            });
        }
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
                if ('expire' !== ActionParams::optionalString($rule, 'onTimeout')) {
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
        $code = $this->releaseCode($run, $pause);
        if (null === $code) {
            $run->holdingPause = $pause;

            return true;
        }

        ($this->releaseCardPause)(new ReleaseCardPauseCommand($pause, $code));
        $rule = $run->rule($pause->ruleId);
        if ('facts-changed' === $code && null !== $rule) {
            // A false truth makes the rule fire again at once, with a fresh backoff.
            $this->write($run, $this->state($run, $rule), static function (WorkflowRuleState $state): void {
                $state->truth = false;
                $state->attempts = 0;
                $state->dueAt = null;
            });
        }

        return false;
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

        return match ($pause->kind) {
            CardPauseKind::Rule => match (true) {
                null === $rule->then->until => 'rule-removed',
                null !== $rule->then->until->unreadable($run->facts) => null,
                $rule->then->until->evaluate($run->facts) => 'until-met',
                default => null,
            },
            CardPauseKind::WorkLimit => $applies ? null : 'left-slot',
            CardPauseKind::Retries, CardPauseKind::WorkTimeout => match (true) {
                !$applies => 'facts-changed',
                null !== $rule->when->unreadable($run->facts) => null,
                !$rule->when->evaluate($run->facts),
                $this->fingerprint->of($run->facts, $rule->when->reads()) !== ($run->states[$rule->id] ?? null)?->fingerprint => 'facts-changed',
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
                    $this->write($run, $state, static fn (WorkflowRuleState $state) => $state->reset());
                }
                continue;
            }
            // Before state(), which persists a new state: a rule that cannot read its facts writes none.
            // A pause whose until cannot be read could never release, so it waits too.
            if (null !== ($rule->when->unreadable($run->facts) ?? $rule->then->until?->unreadable($run->facts))) {
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
        $truth = $rule->when->evaluate($run->facts);
        $fingerprint = $this->fingerprint->of($run->facts, $rule->when->reads());
        if (!$truth) {
            $state->truth = false;
            $state->attempts = 0;
            $state->dueAt = null;
            $state->lastRefusal = null;
            $state->lastRefusalAt = null;
            $state->fingerprint = $fingerprint;

            return true;
        }

        $fire = !$state->truth || ($state->attempts > 0 && ((null !== $state->dueAt && $state->dueAt <= $run->now) || $state->fingerprint !== $fingerprint));
        // A release rule that turned true before its pause existed would otherwise wait for a new edge.
        if (!$fire && ActionType::Release === $rule->then->type) {
            $fire = null !== $run->holdingPause && $run->holdingPause->reason === ActionOutcome::code(ActionParams::string($rule, 'reason'));
        }
        $state->truth = true;
        $state->fingerprint = $fingerprint;
        if (!$fire) {
            return true;
        }

        $type = $rule->then->type;
        $outcome = $this->actions->get($type)->run($rule, $run->card, $run->facts, $state);
        $run->fired[] = ['rule' => $rule->id, 'outcome' => $outcome->kind->value, 'code' => $outcome->code];
        if (null !== $run->holdingPause?->releasedAt) {
            $run->holdingPause = null;
        }

        switch ($outcome->kind) {
            case ActionOutcomeKind::Done:
                $state->attempts = 0;
                $state->dueAt = null;
                $state->lastRefusal = null;
                $state->lastRefusalAt = null;
                if ((ActionType::Request === $type || ActionType::ForgeWrite === $type) && !$outcome->alreadyLive) {
                    ++$state->fires;
                }

                // The move queues the next evaluation, and the rules after it would read the old slot.
                return ActionType::Move !== $type;
            case ActionOutcomeKind::Refused:
                $code = $outcome->code ?? throw new \LogicException('A refusal carries a code.');
                ++$state->attempts;
                $state->lastRefusal = $code;
                $state->lastRefusalAt = $run->now;
                $backoff = $run->template->backoffMinutes[$state->attempts - 1] ?? null;
                if (null !== $backoff) {
                    $state->dueAt = $run->now->add(new \DateInterval(\sprintf('PT%dM', $backoff)));

                    return true;
                }
                $state->dueAt = null;
                $this->pause($run, CardPauseKind::Retries, $code, $rule->id);

                return false;
            case ActionOutcomeKind::Pause:
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

    /** Logs once per evaluation each facts class that a rule of the card reads and no provider gives. */
    private function logMissingProviders(Evaluation $run): void
    {
        $missing = [];
        foreach ($run->template->rules as $rule) {
            if (!$run->applies($rule)) {
                continue;
            }
            foreach ([...$rule->when->reads(), ...($rule->then->until?->reads() ?? [])] as $key) {
                if (\is_string($key) && !\array_key_exists($key, $run->facts->provided)) {
                    $missing[$key] = true;
                }
            }
        }
        foreach (array_keys($missing) as $class) {
            $this->logger->error('workflow.fact_provider_missing', ['cardId' => $run->card->id?->toRfc4122(), 'factsClass' => $class]);
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
        $change($state);
        if (self::snapshot($state) !== $before) {
            $state->updatedAt = $run->now;
        }
    }

    /** @return list<mixed> */
    private static function snapshot(WorkflowRuleState $state): array
    {
        return [$state->truth, $state->attempts, $state->fires, $state->fingerprint, $state->dueAt?->format('U.u'), $state->lastRefusal, $state->lastRefusalAt?->format('U.u')];
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
