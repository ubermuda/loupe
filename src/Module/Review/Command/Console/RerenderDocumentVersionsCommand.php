<?php

declare(strict_types=1);

namespace App\Module\Review\Command\Console;

use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-renders the stored HTML of every document version from its Markdown source.
 *
 * Versions created before a renderer change (e.g. GFM table support) keep their
 * old HTML — this refreshes them. The rendered_html column is updated directly
 * because DocumentVersion::$renderedHtml is readonly (versions are immutable by
 * design); this maintenance command is the one sanctioned exception.
 */
#[AsCommand(
    name: 'app:review:rerender-versions',
    description: 'Re-render stored HTML for all document versions from their Markdown source.',
)]
final class RerenderDocumentVersionsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MarkdownRenderer $renderer,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var \Traversable<int, array{id: string, markdown_source: string}> $rows */
        $rows = $this->connection->iterateAssociative('SELECT id, markdown_source FROM document_versions');

        $total = 0;
        $changed = 0;
        foreach ($rows as $row) {
            ++$total;
            $html = $this->renderer->render($row['markdown_source']);
            $changed += $this->connection->executeStatement(
                'UPDATE document_versions SET rendered_html = :html WHERE id = :id::uuid AND rendered_html <> :html',
                ['html' => $html, 'id' => $row['id']],
            );
        }

        $io->success(sprintf('Re-rendered %d of %d document version(s).', $changed, $total));

        return Command::SUCCESS;
    }
}
