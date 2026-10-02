<?php

declare(strict_types=1);

namespace App\Module\Workflow\View;

/** What the Workflow panel of a card page shows. The texts are translated. */
final readonly class CardWorkflowPanel
{
    public function __construct(
        public CardManagement $management,
        public ?CardWorkflowPause $pause = null,
        /** Null while the engine is off, for a held card, or when the template or the facts cannot be read. */
        public ?CardWorkflowProgress $progress = null,
    ) {
    }
}
