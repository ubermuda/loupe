<?php

declare(strict_types=1);

namespace App\Module\Workflow\Messenger;

use App\Module\Workflow\Command\EvaluateWorkflowCardCommand;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class EvaluateCardHandler
{
    public function __construct(
        private EvaluateWorkflowCardHandler $evaluateWorkflowCard,
    ) {
    }

    public function __invoke(EvaluateCard $message): void
    {
        ($this->evaluateWorkflowCard)(new EvaluateWorkflowCardCommand(Uuid::fromString($message->cardId)));
    }
}
