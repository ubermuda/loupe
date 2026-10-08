<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

/** What the answer to an ask did: the card and the rule, and the code of the refusal that stopped the option. */
final readonly class AnsweredRuleAsk
{
    public function __construct(
        public string $cardId,
        public string $projectId,
        public string $ruleId,
        public ?string $refusal,
    ) {
    }
}
