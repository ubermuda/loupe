<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

/** The owner picked option $optionIndex of the ask that the inbox item $itemId stands for. */
final readonly class AnswerRuleAskCommand
{
    public function __construct(
        public string $itemId,
        public int $optionIndex,
    ) {
    }
}
