<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Service\AnchorService;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;
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
        private AnchorService $anchorService,
    ) {
    }

    public function __invoke(RefreshDocumentVersionsHtmlCommand $command): RefreshDocumentVersionsHtmlResult
    {
        $atRisk = $this->countCommentsThatWouldStopResolving();
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
     * Comments this run would strand, resolved individually rather than inferred
     * from the version's text changing.
     *
     * Comparing whole before/after plain text refuses for any edit anywhere,
     * including text added far from every anchor where each comment still
     * resolves perfectly. Asking the resolver the same question ReanchoringService
     * asks — does this quote still appear — is what makes a refusal mean
     * something. A guard that fires on harmless edits is what teaches people to
     * reach for the override flag by reflex.
     *
     * Untargeted comments are excluded in SQL: an empty quote is never
     * relocated, so counting one would be an alarm that cannot come true.
     *
     * The whole inspection completes before the first write, so a refusal leaves
     * the table exactly as it was rather than half-rewritten. Rows are ordered by
     * version so each version's HTML is rendered once and held for its own
     * comments only.
     */
    private function countCommentsThatWouldStopResolving(): int
    {
        /** @var iterable<array{id: string, markdown_source: string, anchor_quote: string, anchor_prefix: string, anchor_suffix: string, anchor_offset_hint: int}> $rows */
        $rows = $this->connection->iterateAssociative(
            "SELECT v.id, v.markdown_source, c.anchor_quote, c.anchor_prefix, c.anchor_suffix, c.anchor_offset_hint
             FROM document_versions v
             JOIN comments c ON c.version_id = v.id AND c.anchor_quote <> ''
             ORDER BY v.id",
        );

        $atRisk = 0;
        $renderedVersionId = null;
        $plainText = '';

        foreach ($rows as $row) {
            if ($row['id'] !== $renderedVersionId) {
                $renderedVersionId = $row['id'];
                $plainText = DocumentVersion::plainTextOf($this->renderer->render($row['markdown_source']));
            }

            $anchor = new Anchor(
                $row['anchor_quote'],
                $row['anchor_prefix'],
                $row['anchor_suffix'],
                (int) $row['anchor_offset_hint'],
            );

            if (null === $this->anchorService->resolve($plainText, $anchor)) {
                ++$atRisk;
            }
        }

        return $atRisk;
    }
}
