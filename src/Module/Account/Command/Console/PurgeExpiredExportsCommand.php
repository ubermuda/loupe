<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Console;

use App\Module\Account\Service\ExpiredExportPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Deletes expired data-export archives and their rows. The scheduler fires it
 * hourly through the worker; this command is the manual backstop and the
 * dev/e2e seam. Safe to run any time — exports are also opportunistically
 * purged after each successful worker completion, so a run usually finds
 * nothing left to do.
 */
#[AsCommand(
    name: 'app:purge-expired-exports',
    description: 'Delete expired data-export archives and rows. Safe for cron.',
)]
// Purging is background work against the shared export storage, so it belongs
// to the worker that already drains `scheduler_default`, not to the web
// container. The attribute adds this task to the `default` schedule by
// decorating whichever provider owns it, so no schedule class is needed here.
//
// A cron trigger, not `every('1 hour')`: the stateless periodic trigger counts
// down from worker boot, so a worker recycled by --time-limit=3600 restarts the
// countdown and the tick may never fire. The cron grid is wall-clock instead.
// Half past the hour keeps it off the top-of-hour slot the trial sweep already
// occupies, so the two never contend for the same worker turn. Purging is
// idempotent — a duplicate tick re-selects nothing.
#[AsCronTask('30 * * * *')]
final class PurgeExpiredExportsCommand extends Command
{
    public function __construct(
        private readonly ExpiredExportPurger $purger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->purger->purge();

        $io->success(sprintf('Purged %d expired export(s).', $count));

        return Command::SUCCESS;
    }
}
