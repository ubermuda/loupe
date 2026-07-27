<?php

declare(strict_types=1);

namespace App\Module\Review\Command\Console;

use App\Module\Review\Command\RefreshDocumentVersionsHtmlCommand;
use App\Module\Review\Command\RefreshDocumentVersionsHtmlHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Thin shell over `RefreshDocumentVersionsHtmlHandler`, which re-renders the
 * stored HTML of every document version from its Markdown source.
 */
#[AsCommand(
    name: 'app:review:rerender-versions',
    description: 'Re-render stored HTML for all document versions from their Markdown source.',
)]
final class RerenderDocumentVersionsCommand extends Command
{
    public function __construct(
        private readonly RefreshDocumentVersionsHtmlHandler $refreshDocumentVersionsHtml,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = ($this->refreshDocumentVersionsHtml)(new RefreshDocumentVersionsHtmlCommand());

        $io->success(sprintf('Re-rendered %d of %d document version(s).', $result->changed, $result->total));

        return Command::SUCCESS;
    }
}
