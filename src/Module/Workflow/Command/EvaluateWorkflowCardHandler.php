<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Engine\Engine;
use Psr\Clock\ClockInterface;

/** Runs the rules of the workflow template on one card, now. */
final readonly class EvaluateWorkflowCardHandler
{
    public function __construct(
        private Engine $engine,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EvaluateWorkflowCardCommand $command): void
    {
        $this->engine->evaluate($command->cardId, $this->clock->now());
    }
}
