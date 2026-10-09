<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** What the workflow of a project says about the choice an agent states for a card it files under a parent. */
final readonly class ChildDesignTargets
{
    /** @param list<string> $declared the choices the workflow template declares */
    public function __construct(
        public array $declared,
        /** The owner would get the unplanned-child question for this card if the call stated no choice. */
        public bool $choiceRequired,
        /** The parent has a document that the `inherit` choice links, whatever its status. */
        public bool $inheritAvailable,
    ) {
    }
}
