<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Entity\Card;

/** Board may not import Workflow, so the workflow template says what the choices of an agent mean, and Workflow runs them. */
interface ChildDesignChoices
{
    public const string INHERIT = 'inherit';
    public const string OWN = 'own';
    public const array CHOICES = [self::INHERIT, self::OWN];

    /**
     * Null when the workflow of the project declares no choices, or does not run for the project.
     *
     * @param list<string> $documentIds the ids of the documents the card links once the call has run
     */
    public function forChild(Card $parent, array $documentIds): ?ChildDesignTargets;

    /**
     * Runs the card write and the actions of the choice in one transaction. A refusal rolls both back.
     * For a card that exists, the move the choice makes is checked before the write, so a refusal leaves no trace.
     *
     * @param \Closure(): Card $write
     *
     * @throws ChildDesignRefused when the workflow declares no such choice, or an action refuses
     * @throws CardManaged        when the workflow does not allow the move that `own` makes
     */
    public function write(\Closure $write, string $choice, ?CardEventCause $cause = null, ?Card $existing = null): Card;
}
