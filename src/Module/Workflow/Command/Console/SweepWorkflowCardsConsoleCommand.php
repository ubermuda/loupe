<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command\Console;

use App\Module\Workflow\Command\SweepWorkflowCardsCommand;
use App\Module\Workflow\Command\SweepWorkflowCardsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The scheduler runs the same work every ten minutes through the worker. This is the manual backstop. */
#[AsCommand(
    name: 'app:sweep-workflow-cards',
    description: 'Queue an evaluation of every open card of every project bound to a workflow.',
)]
final class SweepWorkflowCardsConsoleCommand extends Command
{
    public function __construct(
        private readonly SweepWorkflowCardsHandler $sweepWorkflowCards,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queued = ($this->sweepWorkflowCards)(new SweepWorkflowCardsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Queued %d evaluation(s).', $queued));

        return Command::SUCCESS;
    }
}
