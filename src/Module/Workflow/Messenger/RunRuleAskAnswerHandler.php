<?php

declare(strict_types=1);

namespace App\Module\Workflow\Messenger;

use App\Module\Workflow\Command\AnswerRuleAskCommand;
use App\Module\Workflow\Command\AnswerRuleAskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunRuleAskAnswerHandler
{
    public function __construct(
        private AnswerRuleAskHandler $answerRuleAsk,
    ) {
    }

    public function __invoke(RunRuleAskAnswer $message): void
    {
        ($this->answerRuleAsk)(new AnswerRuleAskCommand($message->itemId, $message->optionIndex));
    }
}
