<?php

declare(strict_types=1);

namespace App\Module\Board\Command\Console;

use App\Module\Board\Command\AnnounceStuckPullRequestsCommand;
use App\Module\Board\Command\AnnounceStuckPullRequestsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The scheduler runs the same work every minute through the worker. This is the manual backstop. */
#[AsCommand(
    name: 'app:announce-stuck-pull-requests',
    description: 'Refresh the cards whose ready pull request passed the stuck delay of its board.',
)]
final class AnnounceStuckPullRequestsConsoleCommand extends Command
{
    public function __construct(
        private readonly AnnounceStuckPullRequestsHandler $announceStuckPullRequests,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refreshed = ($this->announceStuckPullRequests)(new AnnounceStuckPullRequestsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Refreshed %d card(s).', $refreshed));

        return Command::SUCCESS;
    }
}
