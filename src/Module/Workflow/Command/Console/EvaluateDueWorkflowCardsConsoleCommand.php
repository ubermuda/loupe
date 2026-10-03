<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command\Console;

use App\Module\Workflow\Command\EvaluateDueWorkflowCardsCommand;
use App\Module\Workflow\Command\EvaluateDueWorkflowCardsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The scheduler runs the same work every minute through the worker. This is the manual backstop. */
#[AsCommand(
    name: 'app:evaluate-due-workflow-cards',
    description: 'Queue an evaluation of the cards whose refused workflow rule is due to retry, or whose timed condition is due.',
)]
final class EvaluateDueWorkflowCardsConsoleCommand extends Command
{
    public function __construct(
        private readonly EvaluateDueWorkflowCardsHandler $evaluateDueWorkflowCards,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queued = ($this->evaluateDueWorkflowCards)(new EvaluateDueWorkflowCardsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Queued %d evaluation(s).', $queued));

        return Command::SUCCESS;
    }
}
