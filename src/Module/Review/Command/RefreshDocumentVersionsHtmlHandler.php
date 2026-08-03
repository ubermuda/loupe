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
     * Comments that resolve against the stored HTML and would stop resolving
     * against the re-rendered HTML — the damage this run would newly cause.
     *
     * Two narrowings, both for the same reason: a guard that fires where there
     * is nothing to lose is what teaches people to reach for the override flag
     * by reflex, and then it protects nothing.
     *
     * Comparing whole before/after plain text fires for any edit anywhere,
     * including text added far from every anchor where each comment still
     * resolves. So each anchor is asked individually, using the predicate
     * ReanchoringService uses. And asking only about the new text counts anchors
     * that were already unresolvable — a comment stranded by an earlier revision
     * is no worse off, yet its version could not be re-rendered without the flag.
     *
     * Resolvability is measured rather than read off `comments.orphaned`, which
     * is only written when a document is revised and so can be stale both ways:
     * a flagged comment whose quote has since reappeared still counts here if
     * this run would remove it again, and an unflagged comment whose quote is
     * already gone does not.
     *
     * Untargeted comments are excluded in SQL: an empty quote is never
     * relocated, so counting one would be an alarm that cannot come true.
     *
     * The whole inspection completes before the first write, so a refusal leaves
     * the table exactly as it was rather than half-rewritten. Rows are ordered by
     * version so each version is rendered once.
     */
    private function countCommentsThatWouldStopResolving(): int
    {
        /** @var iterable<array{id: string, markdown_source: string, rendered_html: string, anchor_quote: string, anchor_prefix: string, anchor_suffix: string, anchor_offset_hint: int}> $rows */
        $rows = $this->connection->iterateAssociative(
            "SELECT v.id, v.markdown_source, v.rendered_html,
                    c.anchor_quote, c.anchor_prefix, c.anchor_suffix, c.anchor_offset_hint
             FROM document_versions v
             JOIN comments c ON c.version_id = v.id AND c.anchor_quote <> ''
             ORDER BY v.id",
        );

        $atRisk = 0;
        $inspectedVersionId = null;
        $before = '';
        $after = '';

        foreach ($rows as $row) {
            if ($row['id'] !== $inspectedVersionId) {
                $inspectedVersionId = $row['id'];
                $before = DocumentVersion::plainTextOf($row['rendered_html']);
                $after = DocumentVersion::plainTextOf($this->renderer->render($row['markdown_source']));
            }

            // Identical text resolves identically, so no comment on this version
            // can newly break — the common case in a re-render, and it skips two
            // resolutions per comment.
            if ($before === $after) {
                continue;
            }

            $anchor = new Anchor(
                $row['anchor_quote'],
                $row['anchor_prefix'],
                $row['anchor_suffix'],
                (int) $row['anchor_offset_hint'],
            );

            if (null !== $this->anchorService->resolve($before, $anchor)
                && null === $this->anchorService->resolve($after, $anchor)
            ) {
                ++$atRisk;
            }
        }

        return $atRisk;
    }
}
