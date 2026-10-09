<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Opens a work request of a card for a rule, with what the card holds now as its context. Board implements it. */
interface WorkOpener
{
    /** A given reason replaces the fix reason of the pull request in the context. */
    public function open(ActionContext $context, string $kind, ?string $capability, ?string $reason = null): ActionOutcome;
}
