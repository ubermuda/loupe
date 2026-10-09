<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\RuleAsks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Opens a question about the card in the inbox and answers with the item id.
 * The text is plain in the inbox, so the instance default locale translates it here.
 * With the inbox off, the card pauses until the inbox is on again.
 */
final readonly class Ask implements Action
{
    public const string INBOX_OFF = 'inbox-off';

    public const string KEY = 'ask';

    public function __construct(
        private RuleAsks $asks,
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
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
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('question', ParameterType::String),
            new Parameter('options', ParameterType::Options),
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits();
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.ask', 'workflow.panel.action.ask');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $card = $context->card;
        $projectId = $card->projectId;
        if (!$this->asks->isOn($projectId)) {
            return ActionOutcome::pause(PauseKind::Rule, self::INBOX_OFF);
        }

        $parameters = ['%child%' => $card->number, '%epic%' => $card->parentNumber ?? ''];
        $itemId = $this->asks->open(
            $projectId,
            $card->id,
            $context->ruleId,
            $this->translator->trans($context->string('question'), $parameters, null, $this->locale),
            array_map(
                fn (string $label): string => $this->translator->trans($label, $parameters, null, $this->locale),
                $context->options(),
            ),
        );

        return ActionOutcome::done(askItemId: $itemId);
    }
}
