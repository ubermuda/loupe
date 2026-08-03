<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\DBAL\Connection;

/**
 * Re-renders the stored HTML of every document version from its Markdown
 * source. Versions created before a renderer change (e.g. GFM table support)
 * keep their old HTML — this refreshes them. The rendered_html column is
 * updated directly because DocumentVersion::$renderedHtml is readonly
 * (versions are immutable by design); this maintenance handler is the one
 * sanctioned exception.
 *
 * Rows are streamed via `iterateAssociative()` rather than loaded with
 * `fetchAllAssociative()`, so this scales to the full document_versions
 * table without buffering it in memory.
 */
final readonly class RefreshDocumentVersionsHtmlHandler
{
    public function __construct(
        private Connection $connection,
        private MarkdownRenderer $renderer,
    ) {
    }

    public function __invoke(RefreshDocumentVersionsHtmlCommand $command): RefreshDocumentVersionsHtmlResult
    {
        $atRisk = $this->countVersionsWhoseAnchorsWouldMove();
        if ($atRisk > 0 && !$command->acceptCommentOrphaning) {
            return new RefreshDocumentVersionsHtmlResult(atRisk: $atRisk, refused: true);
        }

        $total = 0;
        $changed = 0;

        /** @var iterable<array{id: string, markdown_source: string}> $rows */
        $rows = $this->connection->iterateAssociative('SELECT id, markdown_source FROM document_versions');

        foreach ($rows as $row) {
            ++$total;
            $html = $this->renderer->render($row['markdown_source']);
            // Connection::executeStatement() is typed int|string (some
            // drivers report the affected-row count as a numeric string);
            // an UPDATE's affected-row count is always numeric.
            $changed += (int) $this->connection->executeStatement(
                'UPDATE document_versions SET rendered_html = :html WHERE id = :id::uuid AND rendered_html <> :html',
                ['html' => $html, 'id' => $row['id']],
            );
        }

        return new RefreshDocumentVersionsHtmlResult($total, $changed, $atRisk);
    }

    /**
     * Versions that carry a comment this run would strand.
     *
     * A whole inspection pass runs before anything is written, so a refusal
     * leaves the table exactly as it was rather than half-rewritten. That costs
     * a second render of every row, which is the right trade for a maintenance
     * command that must not report a count it has already invalidated.
     *
     * Only anchored comments are counted. An untargeted comment carries an empty
     * quote and is never relocated, so including it would raise an alarm that
     * cannot come true — and an alarm that cries wolf is how the opt-in flag
     * becomes something people pass by reflex.
     */
    private function countVersionsWhoseAnchorsWouldMove(): int
    {
        /** @var iterable<array{markdown_source: string, rendered_html: string}> $rows */
        $rows = $this->connection->iterateAssociative(
            "SELECT v.markdown_source, v.rendered_html
             FROM document_versions v
             WHERE EXISTS (
                 SELECT 1 FROM comments c WHERE c.version_id = v.id AND c.anchor_quote <> ''
             )",
        );

        $atRisk = 0;
        foreach ($rows as $row) {
            $before = DocumentVersion::plainTextOf($row['rendered_html']);
            $after = DocumentVersion::plainTextOf($this->renderer->render($row['markdown_source']));
            if ($before !== $after) {
                ++$atRisk;
            }
        }

        return $atRisk;
    }
}
