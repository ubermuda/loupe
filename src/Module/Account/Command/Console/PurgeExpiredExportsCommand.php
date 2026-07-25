<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Console;

use App\Module\Account\Service\ExpiredExportPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes expired data-export archives and their rows. Safe to run from cron
 * — exports are also opportunistically purged after each successful worker
 * completion, so this is a backstop for exports nobody ever re-triggers.
 */
#[AsCommand(
    name: 'app:purge-expired-exports',
    description: 'Delete expired data-export archives and rows. Safe for cron.',
)]
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
