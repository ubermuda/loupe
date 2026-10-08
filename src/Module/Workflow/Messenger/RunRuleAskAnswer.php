<?php

declare(strict_types=1);

namespace App\Module\Workflow\Messenger;

/** The owner picked an option of an ask. The item id finds the rule state that holds the ask. */
final readonly class RunRuleAskAnswer
{
    public function __construct(
        public string $itemId,
        public int $optionIndex,
    ) {
    }
}
