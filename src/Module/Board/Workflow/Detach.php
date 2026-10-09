<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;

/** Removes the parent of the card. A card with no parent stays as it is. */
final readonly class Detach implements Action
{
    public const string DETACH_REFUSED = 'detach-refused';

    public const string KEY = 'detach';

    public function __construct(
        private CardRepository $cards,
        private UpdateCardHandler $updateCard,
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
        return [];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(option: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.detach', 'workflow.panel.action.detach');
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
        if (!$context->facts->card->isChild) {
            return ActionOutcome::done();
        }

        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: Actor::System,
                parentCardId: '',
                cause: CardEventCause::workflowRule($context->ruleId),
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused(self::DETACH_REFUSED);
        }

        return ActionOutcome::done();
    }
}
