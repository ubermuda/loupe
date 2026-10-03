<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

/** Queues an evaluation of every card with a refused rule whose retry is due. It carries no data. */
final readonly class EvaluateDueWorkflowCardsCommand
{
}
