<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A source of state reasons that Board cannot read itself, because the module that owns the facts
 * may not be imported here. Inbox and Workflow implement it. Each implementation answers for all cards in one query per fact.
 */
#[AutoconfigureTag('app.card_state_signals')]
interface CardStateSignalsInterface
{
    /**
     * @param list<Card> $cards the cards of the project that sit in a column that is not terminal
     *
     * @return array<string, non-empty-list<CardStateReason>> card id => its reasons; a card with none has no key
     */
    public function signalsFor(Project $project, array $cards): array;
}
