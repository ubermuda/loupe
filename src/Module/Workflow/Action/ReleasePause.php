<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;

/** Lifts the active pause of a card when its reason is the reason of the rule. */
final readonly class ReleasePause implements Action
{
    public const string RELEASE_REASON = 'released-by-rule';

    public const string KEY = 'release';

    public function __construct(
        private CardPauses $cardPauses,
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
            new Parameter('reason', ParameterType::String),
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
        return new ActionDescription('workflow.settings.action.release', 'workflow.panel.action.release');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $pause = $this->cardPauses->findActive($context->card->id);
        if (null !== $pause && $pause->reason === ActionOutcome::code($context->string('reason'))) {
            $this->cardPauses->release($pause, self::RELEASE_REASON);
        }

        return ActionOutcome::done();
    }
}
