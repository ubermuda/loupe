<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;

/** Asks the engine to evaluate every child of the card again. */
final readonly class EvaluateChildren implements Action
{
    public const string KEY = 'evaluate';

    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $evaluations,
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
            new Parameter('cards', ParameterType::String, choices: ['children']),
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
        return new ActionDescription('workflow.settings.action.evaluate', 'workflow.panel.action.evaluate');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        $ids = array_values(array_filter(array_map(static fn (Card $child) => $child->id, $this->cards->findChildren($card))));
        if ([] !== $ids) {
            $this->evaluations->forCards($ids);
        }

        return ActionOutcome::done();
    }
}
