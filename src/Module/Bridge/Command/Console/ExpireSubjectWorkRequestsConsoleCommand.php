<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command\Console;

use App\Module\Bridge\Command\ExpireSubjectWorkRequestsCommand;
use App\Module\Bridge\Command\ExpireSubjectWorkRequestsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Expires the open requests about a subject other than a card that no bridge
 * took in time, once. The scheduler runs the same work every minute through
 * the worker. This is the manual backstop.
 */
#[AsCommand(
    name: 'app:expire-subject-work-requests',
    description: 'Expire the open work requests about a subject other than a card that no bridge took in time.',
)]
final class ExpireSubjectWorkRequestsConsoleCommand extends Command
{
    public function __construct(
        private readonly ExpireSubjectWorkRequestsHandler $expireSubjectWorkRequests,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = ($this->expireSubjectWorkRequests)(new ExpireSubjectWorkRequestsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Expired %d work request(s).', $expired));

        return Command::SUCCESS;
    }
}
