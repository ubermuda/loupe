<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\RuleAsks;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\AskOption;
use App\Module\Workflow\Template\Rule;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Opens a question about the card in the inbox and keeps the item id on the rule state.
 * The text is plain in the inbox, so the instance default locale translates it here.
 * With the inbox off, the card pauses until the inbox is on again.
 */
final readonly class Ask implements Action
{
    public const string INBOX_OFF = 'inbox-off';

    public function __construct(
        private RuleAsks $asks,
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Ask;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $projectId = $card->project->id ?? throw new \LogicException('A stored card has a project id.');
        if (!$this->asks->isOn($projectId)) {
            return ActionOutcome::pause(CardPauseKind::Rule, self::INBOX_OFF);
        }

        $parameters = ['%child%' => $card->number, '%epic%' => $card->parent->number ?? ''];
        $state->askItemId = $this->asks->open(
            $projectId,
            $card->id ?? throw new \LogicException('A stored card has an id.'),
            $rule->id,
            $this->translator->trans(ActionParams::string($rule, 'question'), $parameters, null, $this->locale),
            array_map(
                fn (AskOption $option): string => $this->translator->trans($option->label, $parameters, null, $this->locale),
                $rule->then->options,
            ),
        );

        return ActionOutcome::done();
    }
}
