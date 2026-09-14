<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command\Console;

use App\Module\Bridge\Command\PurgeExpiredWorkerRunsCommand;
use App\Module\Bridge\Command\PurgeExpiredWorkerRunsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sweeps expired worker runs once. The scheduler runs the same work every hour
 * through the worker; this is the manual backstop for an instance whose worker
 * has been down.
 */
#[AsCommand(
    name: 'app:purge-worker-runs',
    description: 'Delete worker run records older than the retention window.',
)]
final class PurgeWorkerRunsConsoleCommand extends Command
{
    public function __construct(
        private readonly PurgeExpiredWorkerRunsHandler $purgeExpiredWorkerRuns,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $purged = ($this->purgeExpiredWorkerRuns)(new PurgeExpiredWorkerRunsCommand());

        $io->success(sprintf('Purged %d worker run record(s).', $purged));

        return Command::SUCCESS;
    }
}
