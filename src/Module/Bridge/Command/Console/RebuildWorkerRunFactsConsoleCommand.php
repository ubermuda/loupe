<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command\Console;

use App\Module\Bridge\Command\RebuildWorkerRunFactsCommand;
use App\Module\Bridge\Command\RebuildWorkerRunFactsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Brings every fact row up to date. A container that runs code older than the
 * fact listener changes runs and leaves their fact rows stale, so run this
 * once after such a container stops.
 */
#[AsCommand(
    name: 'app:bridge:rebuild-run-facts',
    description: 'Rewrite the fact row of every worker run from the run and its usage.',
)]
final class RebuildWorkerRunFactsConsoleCommand extends Command
{
    public function __construct(
        private readonly RebuildWorkerRunFactsHandler $rebuildWorkerRunFacts,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $written = ($this->rebuildWorkerRunFacts)(new RebuildWorkerRunFactsCommand());

        $io->success(\sprintf('Rebuilt the fact rows of %d worker run(s).', $written));

        return Command::SUCCESS;
    }
}
