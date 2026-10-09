<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Workflow\Action\ActionParams;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use App\Module\Workflow\View\CardWorkflowPanel;
use App\Module\Workflow\View\CardWorkflowPause;
use App\Module\Workflow\View\CardWorkflowProgress;
use App\Module\Workflow\View\CardWorkflowRefusal;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Reads what the Workflow panel of a card shows. It writes nothing. */
final readonly class CardWorkflowPanelBuilder
{
    public function __construct(
        private WorkflowAutomation $automation,
        private CardHolds $cardHolds,
        private CardPauseRepository $cardPauses,
        private TemplateSource $templates,
        private FactsBuilder $factsBuilder,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private RuleSubject $ruleSubject,
    ) {
    }

    public function build(Card $card): CardWorkflowPanel
    {
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $projectId = $card->project->id ?? throw new \LogicException('A stored project has an id.');
        $managed = !$this->cardHolds->isHeld($card->project, $cardId) && $this->automation->runsFor($card->project);
        $pause = $this->cardPauses->findActiveForCard($card);

        $template = null;
        $facts = null;
        $progress = null;
        try {
            $template = $this->templates->forProject($projectId);
            if ($managed || CardPauseKind::Rule === $pause?->kind) {
                $facts = $this->factsBuilder->build($card, $this->clock->now());
            }
            if ($managed && null !== $facts) {
                $progress = $this->progress($card, $template, $facts);
            }
        } catch (TemplateMissing) {
        } catch (\Throwable $e) {
            // The card page must render whatever the template or the facts hold.
            $template = null;
            $facts = null;
            $this->logger->warning('workflow.panel_degraded', ['cardId' => $cardId->toRfc4122(), 'exception' => $e]);
        }

        return new CardWorkflowPanel(
            null === $pause ? null : $this->pause($pause, $template, $facts, $managed),
            $progress,
        );
    }

    private function pause(CardPause $pause, ?Template $template, ?Facts $facts, bool $managed): CardWorkflowPause
    {
        $release = match ($pause->kind) {
            CardPauseKind::Rule => $this->ruleRelease($pause, $template, $facts),
            CardPauseKind::WorkLimit => $this->translator->trans('workflow.panel.release.left_slot'),
            CardPauseKind::Retries, CardPauseKind::WorkTimeout, CardPauseKind::WorkStopped => $this->translator->trans('workflow.panel.release.facts_changed'),
        };

        return new CardWorkflowPause(
            (string) $pause->id,
            $pause->reason,
            $this->translator->trans('workflow.panel.pause_kind.'.str_replace('-', '_', $pause->kind->value)),
            $this->codeText(['workflow.pause.', 'workflow.refusal.'], $pause->reason),
            $release,
            $pause->createdAt,
            $managed && \in_array($pause->kind, ReleaseWorkflowPauseCommand::RELEASABLE_KINDS, true),
        );
    }

    private function ruleRelease(CardPause $pause, ?Template $template, ?Facts $facts): string
    {
        $rule = null === $template ? null : self::rule($template, $pause->ruleId);
        $until = $rule?->then->until;
        if (null === $rule || null === $until || null === $facts || null !== $until->unreadable($facts)) {
            return $this->translator->trans('workflow.panel.release.next_evaluation');
        }

        $stored = ($this->workflowRuleStates->findForCard($pause->card)[$rule->id] ?? null)?->subjectPullRequestId;
        $leaf = $until->firstFalseLeaf($this->ruleSubject->paused($rule, $facts, $stored));

        return null === $leaf
            ? $this->translator->trans('workflow.panel.release.met')
            : $leaf->waitingFor()->trans($this->translator);
    }

    private function progress(Card $card, Template $template, Facts $facts): CardWorkflowProgress
    {
        $rules = $template->rulesFor($facts->card->slot);
        $falseRules = array_values(array_filter(
            $rules,
            fn (Rule $rule): bool => null !== $rule->when->unreadable($facts) || null !== $rule->then->until?->unreadable($facts) || !$this->ruleSubject->bind($rule, $facts)->truth,
        ));
        $blocking = array_find($falseRules, static fn (Rule $rule): bool => ActionType::Move === $rule->then->type) ?? $falseRules[0] ?? null;
        $waiting = null;
        if (null !== $blocking) {
            $bound = $this->ruleSubject->bind($blocking, $facts);
            $unreadable = $blocking->when->unreadable($facts);
            // The engine reads the until of a pause only once its when is true.
            if (null === $unreadable && $bound->truth) {
                $unreadable = $blocking->then->until?->unreadable($facts);
            }
            $waiting = null === $unreadable
                ? $blocking->when->firstFalseLeaf($bound->facts)?->waitingFor()->trans($this->translator)
                : $this->unreadableReason($unreadable);
        }

        return new CardWorkflowProgress(
            $this->slotLabel($template, $facts->card->slot),
            $waiting,
            null === $blocking ? null : $this->nextAction($template, $blocking),
            $this->lastRefusal($card),
        );
    }

    private function unreadableReason(Unreadable $unreadable): string
    {
        return match ($unreadable->kind) {
            UnreadableKind::Failed => $this->translator->trans('workflow.panel.unreadable.failed', ['%source%' => $this->translator->trans($unreadable->source)]),
            UnreadableKind::Off => $this->translator->trans('workflow.panel.unreadable.off', ['%source%' => $this->translator->trans($unreadable->source)]),
            UnreadableKind::MissingAction => $this->translator->trans('workflow.panel.unreadable.missing_action', ['%action%' => $unreadable->source]),
            UnreadableKind::MissingCondition => $this->translator->trans('workflow.panel.unreadable.missing_condition', ['%condition%' => $unreadable->source]),
        };
    }

    private function nextAction(Template $template, Rule $rule): string
    {
        return match ($rule->then->type) {
            ActionType::Move => match ($to = ActionParams::string($rule, 'to')) {
                FactsBuilder::BACKLOG_SLOT => $this->translator->trans('workflow.panel.action.move_backlog'),
                FactsBuilder::TERMINAL_SLOT => $this->translator->trans('workflow.panel.action.move_terminal'),
                default => $this->translator->trans('workflow.panel.action.move', ['%slot%' => $this->slotLabel($template, $to)]),
            },
            ActionType::Request => $this->translator->trans('workflow.panel.action.request', ['%kind%' => ActionParams::string($rule, 'kind')]),
            ActionType::ForgeWrite => $this->translator->trans('workflow.panel.action.forge_write', ['%write%' => ActionParams::string($rule, 'write')]),
            ActionType::Pause => $this->translator->trans('workflow.panel.action.pause'),
            ActionType::Release => $this->translator->trans('workflow.panel.action.release'),
            ActionType::Evaluate => $this->translator->trans('workflow.panel.action.evaluate'),
            ActionType::Ask => $this->translator->trans('workflow.panel.action.ask'),
            ActionType::LinkDocument => $this->translator->trans('workflow.panel.action.link_document'),
            ActionType::Detach => $this->translator->trans('workflow.panel.action.detach'),
            ActionType::Missing => $this->translator->trans('workflow.panel.action.missing'),
        };
    }

    private function lastRefusal(Card $card): ?CardWorkflowRefusal
    {
        $latest = null;
        foreach ($this->workflowRuleStates->findForCard($card) as $state) {
            if (null !== $state->lastRefusal && null !== $state->lastRefusalAt && (null === $latest || $state->lastRefusalAt > $latest->at)) {
                $latest = new CardWorkflowRefusal($state->lastRefusal, $this->codeText(['workflow.refusal.'], $state->lastRefusal), $state->lastRefusalAt, $state->attempts);
            }
        }

        return $latest;
    }

    private function slotLabel(Template $template, ?string $slot): string
    {
        return match ($slot) {
            null => $this->translator->trans('workflow.panel.slot.none'),
            FactsBuilder::BACKLOG_SLOT => $this->translator->trans('workflow.panel.slot.backlog'),
            FactsBuilder::TERMINAL_SLOT => $this->translator->trans('workflow.panel.slot.terminal'),
            default => $this->translator->trans($template->slot($slot)->label ?? $slot),
        };
    }

    /**
     * A retries pause keeps the code of its last refusal. An untranslated code shows as it is,
     * because a forge write may refuse with a cause from the forge.
     *
     * @param list<string> $prefixes
     */
    private function codeText(array $prefixes, string $code): string
    {
        foreach ($prefixes as $prefix) {
            $text = $this->translator->trans($prefix.$code);
            if ($text !== $prefix.$code) {
                return $text;
            }
        }

        return $code;
    }

    private static function rule(Template $template, string $ruleId): ?Rule
    {
        return array_find($template->rules, static fn (Rule $rule): bool => $rule->id === $ruleId);
    }
}
