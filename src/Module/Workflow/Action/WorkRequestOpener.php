<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Workflow\Template\Rule;

/** Opens a work request of a card for a rule. A live request of the kind already does the work. */
final readonly class WorkRequestOpener
{
    public function __construct(
        private OpenWorkRequestHandler $openWorkRequest,
    ) {
    }

    public function open(Rule $rule, Card $card, string $kind, ?string $capability): ActionOutcome
    {
        try {
            ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $card->project,
                cardId: $card->id ?? throw new \LogicException('A stored card has an id.'),
                cardNumber: $card->number,
                kind: $kind,
                capability: $capability,
                ruleId: $rule->id,
            ));
        } catch (DomainErrors $e) {
            return \in_array(OpenWorkRequestHandler::LIVE, $e->errors, true)
                ? ActionOutcome::done()
                : ActionOutcome::refused('invalid-work-request');
        }

        return ActionOutcome::done();
    }
}
