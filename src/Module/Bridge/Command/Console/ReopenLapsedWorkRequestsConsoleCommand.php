<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command\Console;

use App\Module\Bridge\Command\ReopenLapsedWorkRequestsCommand;
use App\Module\Bridge\Command\ReopenLapsedWorkRequestsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Opens again the work requests whose claim lease ran out, once. The scheduler
 * runs the same work every minute through the worker. This is the manual backstop.
 */
#[AsCommand(
    name: 'app:reopen-lapsed-work-requests',
    description: 'Open again the claimed work requests whose lease ran out.',
)]
final class ReopenLapsedWorkRequestsConsoleCommand extends Command
{
    public function __construct(
        private readonly ReopenLapsedWorkRequestsHandler $reopenLapsedWorkRequests,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reopened = ($this->reopenLapsedWorkRequests)(new ReopenLapsedWorkRequestsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Reopened %d work request(s).', $reopened));

        return Command::SUCCESS;
    }
}
