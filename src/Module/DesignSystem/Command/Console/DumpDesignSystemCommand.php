<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Command\Console;

use App\Module\DesignSystem\Command\ExportDesignSystemCommand;
use App\Module\DesignSystem\Command\ExportDesignSystemHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes the Claude Design copy of the design system into a directory and
 * prints each path it wrote, relative to that directory, one per line.
 */
#[AsCommand(
    name: 'app:design-system:export',
    description: 'Write the Claude Design copy of the design system into a directory.',
)]
final class DumpDesignSystemCommand extends Command
{
    public function __construct(
        private readonly ExportDesignSystemHandler $exportDesignSystem,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('dir', InputArgument::REQUIRED, 'The directory to write into');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getArgument('dir');
        if (!\is_string($directory) || '' === $directory) {
            throw new \InvalidArgumentException('The dir argument must be a non-empty path.');
        }

        foreach (($this->exportDesignSystem)(new ExportDesignSystemCommand($directory)) as $path) {
            $output->writeln($path, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
