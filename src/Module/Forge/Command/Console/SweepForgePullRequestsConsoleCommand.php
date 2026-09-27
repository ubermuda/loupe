<?php

declare(strict_types=1);

namespace App\Module\Forge\Command\Console;

use App\Module\Forge\Command\SweepForgePullRequestsCommand;
use App\Module\Forge\Command\SweepForgePullRequestsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The manual backstop for the scheduled sweep. */
#[AsCommand(
    name: 'app:sweep-forge-pull-requests',
    description: 'Queue a state refresh of each open pull request not read in the last ten minutes.',
)]
final class SweepForgePullRequestsConsoleCommand extends Command
{
    public function __construct(
        private readonly SweepForgePullRequestsHandler $sweepForgePullRequests,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queued = ($this->sweepForgePullRequests)(new SweepForgePullRequestsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Queued %d pull request refresh(es).', $queued));

        return Command::SUCCESS;
    }
}
