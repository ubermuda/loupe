<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command\Console;

use App\Module\Bridge\Command\TimeOutQuietWorkerRunsCommand;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Times out the open runs of quiet bridges once. The scheduler runs the same
 * work every minute through the worker; this is the manual backstop.
 */
#[AsCommand(
    name: 'app:time-out-worker-runs',
    description: 'Mark the open worker runs of quiet bridges as timed out.',
)]
final class TimeOutWorkerRunsConsoleCommand extends Command
{
    public function __construct(
        private readonly TimeOutQuietWorkerRunsHandler $timeOutQuietWorkerRuns,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timedOut = \count(($this->timeOutQuietWorkerRuns)(new TimeOutQuietWorkerRunsCommand()));

        new SymfonyStyle($input, $output)->success(\sprintf('Timed out %d worker run(s).', $timedOut));

        return Command::SUCCESS;
    }
}
