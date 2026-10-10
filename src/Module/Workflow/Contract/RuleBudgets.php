<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Reads how often a rule fired on a card, and the limit its action sets. The engine implements it. */
interface RuleBudgets
{
    /** The fires of the rule on the card, or null when the rule has no state on the card. */
    public function fires(Uuid $cardId, string $ruleId): ?int;

    /** The `limit` parameter of the rule's action in the template of the project, or null when the rule or the limit is unknown. */
    public function limit(Uuid $projectId, string $ruleId): ?int;
}
