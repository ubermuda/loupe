<?php

declare(strict_types=1);

namespace App\Module\Workflow\Messenger;

use App\Module\Workflow\Command\EvaluateWorkflowCardCommand;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Engine\EngineSwitch;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class EvaluateCardHandler
{
    public function __construct(
        private EvaluateWorkflowCardHandler $evaluateWorkflowCard,
        private EngineSwitch $engine,
    ) {
    }

    public function __invoke(EvaluateCard $message): void
    {
        // A message queued before the switch went off does nothing.
        if (!$this->engine->isOn()) {
            return;
        }

        ($this->evaluateWorkflowCard)(new EvaluateWorkflowCardCommand(Uuid::fromString($message->cardId)));
    }
}
