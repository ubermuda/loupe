<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\Ask;
use App\Module\Workflow\Contract\ActionOutcomeKind;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\WorkLedger;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Runs the actions of the option that the owner picked, in order, under the lock of the card.
 * The first refusal stops the run and stays on the rule state, where the workflow panel of the card shows it.
 * The rule state keeps the item id, because the engine withdraws the item when the rule stops holding.
 */
final readonly class AnswerRuleAskHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private CardRepository $cards,
        private WorkLedger $ledger,
        private WorkflowAutomation $automation,
        private TemplateSource $templates,
        private FactsBuilder $factsBuilder,
        private RuleSubject $ruleSubject,
        private Actions $actions,
        private CardEvaluations $evaluations,
        private ClockInterface $clock,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AnswerRuleAskCommand $command): void
    {
        $itemId = Uuid::fromString($command->itemId);
        $outcome = $this->em->wrapInTransaction(fn (): ?AnsweredRuleAsk => $this->answer($itemId, $command->optionIndex));
        if (null === $outcome) {
            return;
        }

        $this->auditor->record(
            'workflow.ask_answered',
            null === $outcome->refusal ? AuditOutcome::Success : AuditOutcome::Refused,
            [
                'cardId' => $outcome->cardId,
                'projectId' => $outcome->projectId,
                'ruleId' => $outcome->ruleId,
                'optionIndex' => $command->optionIndex,
                'refusal' => $outcome->refusal,
            ],
            new AuditSubject('card', $outcome->cardId),
        );
    }

    private function answer(Uuid $itemId, int $optionIndex): ?AnsweredRuleAsk
    {
        $held = $this->workflowRuleStates->findOneByAskItemId($itemId);
        if (null === $held) {
            return $this->skip('no-rule-state', $itemId);
        }
        $this->workflowRuleStates->lockCard($held->cardId);
        // Read again under the lock: a withdrawal may have cleared the item while this message waited.
        $state = $this->workflowRuleStates->findOneByAskItemId($itemId);
        if (null === $state) {
            return $this->skip('withdrawn', $itemId);
        }
        $this->em->refresh($state);

        $cardId = $state->cardId;
        $card = $this->cards->find($cardId) ?? throw new \LogicException('A rule state belongs to a card that exists.');
        $projectId = $card->project->id ?? throw new \LogicException('A persisted project has an id.');
        if ($this->ledger->isHeld($projectId, $cardId) || !$this->automation->runsFor($card->project)) {
            return $this->skip('card-unmanaged', $itemId);
        }

        try {
            $template = $this->templates->forProject($projectId);
        } catch (TemplateMissing) {
            return $this->skip('no-template', $itemId);
        }
        $rule = array_find($template->rules, static fn (Rule $rule): bool => $rule->id === $state->ruleId);
        $option = Ask::KEY === $rule?->then->key ? ($rule->then->options[$optionIndex] ?? null) : null;
        if (null === $rule || null === $option) {
            return $this->skip('no-option', $itemId);
        }

        $this->cards->refreshColumn($card);
        $this->cards->refreshTypeAndParent($card);
        $now = $this->clock->now();
        $snapshot = $card->snapshot();
        $facts = $this->factsBuilder->build($snapshot, $now);
        if (null !== $rule->slot && $facts->card->slot !== $rule->slot) {
            return $this->skip('left-slot', $itemId);
        }
        if (null === $rule->when->unreadable($facts) && !$this->ruleSubject->bind($rule, $facts)->truth) {
            return $this->skip('rule-false', $itemId);
        }
        $refusal = null;
        foreach ($option->actions as $call) {
            // Facts again for each action: the one before may have changed the card.
            $facts = $this->factsBuilder->build($snapshot, $now);
            $optionRule = new Rule($rule->id, $rule->slot, $rule->when, $call, $rule->origin);
            $result = $this->actions->get($call->key)->run($optionRule->context($snapshot, $facts, $state->fires));
            if (ActionOutcomeKind::Done !== $result->kind) {
                $refusal = $result->code ?? $result->kind->value;
                $state->lastRefusal = $refusal;
                $state->lastRefusalAt = $now;
                $state->updatedAt = $now;
                break;
            }
        }
        $this->em->flush();
        $this->evaluations->forCards([$cardId]);

        return new AnsweredRuleAsk($cardId->toRfc4122(), $projectId->toRfc4122(), $rule->id, $refusal);
    }

    private function skip(string $reason, Uuid $itemId): null
    {
        $this->logger->info('workflow.ask_answer_skipped', ['itemId' => $itemId->toRfc4122(), 'reason' => $reason]);

        return null;
    }
}
