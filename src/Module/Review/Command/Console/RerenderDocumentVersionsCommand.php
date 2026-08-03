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
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = ($this->refreshDocumentVersionsHtml)(new RefreshDocumentVersionsHtmlCommand(
            acceptCommentOrphaning: true === $input->getOption(self::ACCEPT_ORPHANING),
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
                'The missing piece is a reanchoring pass over the rewritten versions. It is',
                'tracked in docs/NEXT_STEPS.md under "Re-rendering stored versions un-highlights',
                'comments without flagging them".',
                '',
                sprintf('To accept that damage anyway, re-run with --%s.', self::ACCEPT_ORPHANING),
            ]);

            return Command::FAILURE;
        }

        $io->success(sprintf('Re-rendered %d of %d document version(s).', $result->changed, $result->total));

        if ($result->atRisk > 0) {
            $io->warning(sprintf('%d comment(s) no longer resolve and will not highlight.', $result->atRisk));
        }

        return Command::SUCCESS;
    }
}
