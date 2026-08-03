<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\AnchorService;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;

/**
 * Re-renders the stored HTML of every document version from its Markdown
 * source. Versions created before a renderer change (e.g. GFM table support)
 * keep their old HTML — this refreshes them.
 *
 * Both repository methods it walks are cursors rather than fetches, so this
 * scales to the full document_versions table without buffering it in memory.
 */
final readonly class RefreshDocumentVersionsHtmlHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
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

        foreach ($this->documentVersions->streamAllSources() as $row) {
            ++$total;
            $changed += $this->documentVersions->updateRenderedHtml(
                $row['id'],
                $this->renderer->render($row['markdown_source']),
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
     * Untargeted comments never reach this loop — the repository filters them
     * out, because an empty quote is never relocated and counting one would be
     * an alarm that cannot come true.
     *
     * The whole inspection completes before the first write, so a refusal leaves
     * the table exactly as it was rather than half-rewritten. It relies on the
     * repository's by-version ordering to render each version once.
     */
    private function countCommentsThatWouldStopResolving(): int
    {
        $atRisk = 0;
        $inspectedVersionId = null;
        $before = '';
        $after = '';

        foreach ($this->documentVersions->streamAnchoredCommentsByVersion() as $row) {
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
                $row['anchor_offset_hint'],
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
