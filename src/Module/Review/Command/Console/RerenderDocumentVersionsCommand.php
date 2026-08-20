<?php

declare(strict_types=1);

namespace App\Module\Review\Command\Console;

use App\Module\Review\Command\RefreshDocumentVersionsHtmlCommand;
use App\Module\Review\Command\RefreshDocumentVersionsHtmlHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
    private const string ACCEPT_ORPHANING = 'accept-comment-orphaning';
    private const string REANCHOR = 'reanchor';

    public function __construct(
        private readonly RefreshDocumentVersionsHtmlHandler $refreshDocumentVersionsHtml,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            self::ACCEPT_ORPHANING,
            null,
            InputOption::VALUE_NONE,
            'Re-render even where it strands existing comments. They will stop highlighting.',
        );
        $this->addOption(
            self::REANCHOR,
            null,
            InputOption::VALUE_NONE,
            'Move every comment anchor onto the re-rendered text, marking the ones it no longer contains.',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = ($this->refreshDocumentVersionsHtml)(new RefreshDocumentVersionsHtmlCommand(
            acceptCommentOrphaning: true === $input->getOption(self::ACCEPT_ORPHANING),
            reanchor: true === $input->getOption(self::REANCHOR),
        ));

        if ($result->refused) {
            $io->error(sprintf(
                'Refusing: %d comment(s) would no longer resolve after this re-render. Nothing was written.',
                $result->atRisk,
            ));
            $io->text([
                'Those comments would silently stop highlighting. The browser finds each one by',
                'its quoted text, so a quote the new rendering no longer contains simply matches',
                'nothing — the comment stays in the sidebar looking healthy, and is only marked',
                'orphaned whenever someone next revises that document.',
                '',
                sprintf('Re-run with --%s to move every anchor onto the new text instead. Comments', self::REANCHOR),
                'it can no longer find are marked orphaned there and then, rather than waiting for',
                'someone to revise the document.',
                '',
                sprintf('To accept the damage without reanchoring, re-run with --%s.', self::ACCEPT_ORPHANING),
            ]);

            return Command::FAILURE;
        }

        $io->success(sprintf('Re-rendered %d of %d document version(s).', $result->changed, $result->total));

        if ($result->reanchored > 0 || $result->orphaned > 0) {
            $io->text(sprintf('Reanchored %d comment(s) onto the new text.', $result->reanchored));
        }

        if ($result->orphaned > 0) {
            $io->warning(sprintf(
                '%d comment(s) quote text the new rendering no longer contains, and are now marked orphaned.',
                $result->orphaned,
            ));
        }

        // Only where nothing was reanchored: after a reanchor run the orphaned
        // count above is the truth, and reporting both says the same damage twice.
        if ($result->atRisk > 0 && 0 === $result->reanchored && 0 === $result->orphaned) {
            $io->warning(sprintf('%d comment(s) no longer resolve and will not highlight.', $result->atRisk));
        }

        return Command::SUCCESS;
    }
}
