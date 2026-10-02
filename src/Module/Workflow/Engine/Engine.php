<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Action\ActionOutcomeKind;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Event\CardPaused;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\FactFingerprint;
use App\Module\Workflow\Service\FactsBuilder;
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
        if (null === $card) {
            return null;
        }
        $this->cards->refreshColumn($card);
        $this->cards->refreshTypeAndParent($card);

        try {
            $template = $this->templates->forProject($card->project->id ?? throw new \LogicException('A persisted project has an id.'));
        } catch (TemplateMissing) {
            return null;
        }

        $run = new Evaluation($card, $template, $this->factsBuilder->build($card, $now), $this->workflowRuleStates->findForCard($card), $now);
        $this->settleWorkRequests($run, $cardId);
        if ($this->stillPaused($run)) {
            $this->runRules($run, array_filter($template->rules, static fn (Rule $rule): bool => ActionType::Release === $rule->then->type));
        } else {
            $this->runRules($run, $template->rules);
        }
        $this->em->flush();

        return $run;
    }

    /** Cancels the live requests of rules that no longer apply, and expires the open ones no bridge took in time. */
    private function settleWorkRequests(Evaluation $run, Uuid $cardId): void
    {
        $withdrawn = false;
        foreach ($this->workRequests->findLiveForCard($cardId) as $request) {
            $requestId = $request->id ?? throw new \LogicException('A persisted work request has an id.');
            $rule = $run->rule($request->ruleId);
            if (null === $rule || !$run->applies($rule)) {
                $withdrawn = ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($requestId, WorkRequestState::Cancelled)) || $withdrawn;
                continue;
            }

            $deadline = $request->createdAt->add(new \DateInterval(\sprintf('PT%dM', $run->template->workTimeoutMinutes)));
            if (WorkRequestState::Open === $request->state && $deadline <= $run->now
                && ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($requestId, WorkRequestState::Expired))) {
                $withdrawn = true;
                $this->pause($run, CardPauseKind::WorkTimeout, self::NO_BRIDGE_TOOK_WORK, $rule->id);
            }
        }

        if ($withdrawn) {
            $run->facts = $this->factsBuilder->build($run->card, $run->now);
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
                $rule->then->until->evaluate($run->facts) => 'until-met',
                default => null,
            },
            CardPauseKind::WorkLimit => $applies ? null : 'left-slot',
            CardPauseKind::Retries, CardPauseKind::WorkTimeout => (
                !$applies
                || !$rule->when->evaluate($run->facts)
                || $this->fingerprint->of($run->facts, $rule->when->reads()) !== ($run->states[$rule->id] ?? null)?->fingerprint
            ) ? 'facts-changed' : null,
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
            $state->fingerprint = $fingerprint;

            return true;
        }

        $fire = !$state->truth || ($state->attempts > 0 && ((null !== $state->dueAt && $state->dueAt <= $run->now) || $state->fingerprint !== $fingerprint));
        $state->truth = true;
        $state->fingerprint = $fingerprint;
        if (!$fire) {
            return true;
        }

        $type = $rule->then->type;
        $outcome = $this->actions->get($type)->run($rule, $run->card, $run->facts, $state);
        $run->fired[] = ['rule' => $rule->id, 'outcome' => $outcome->kind->value, 'code' => $outcome->code];

        switch ($outcome->kind) {
            case ActionOutcomeKind::Done:
                $state->attempts = 0;
                $state->dueAt = null;
                $state->lastRefusal = null;
                if (ActionType::Request === $type || ActionType::ForgeWrite === $type) {
                    ++$state->fires;
                }

                // The move queues the next evaluation, and the rules after it would read the old slot.
                return ActionType::Move !== $type;
            case ActionOutcomeKind::Refused:
                $code = $outcome->code ?? throw new \LogicException('A refusal carries a code.');
                ++$state->attempts;
                $state->lastRefusal = $code;
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
        return [$state->truth, $state->attempts, $state->fires, $state->fingerprint, $state->dueAt?->format('U.u'), $state->lastRefusal];
    }

    private function pause(Evaluation $run, CardPauseKind $kind, string $code, string $ruleId): void
    {
        $pause = ($this->pauseCard)(new PauseCardCommand($run->card, $code, $ruleId, $kind));
        if (null !== $pause) {
            $run->pauses[] = $pause;
        }
    }
}
