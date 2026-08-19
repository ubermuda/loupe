<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\AnchorService;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;

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
        private CommentRepository $comments,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RefreshDocumentVersionsHtmlCommand $command): RefreshDocumentVersionsHtmlResult
    {
        $atRisk = $this->countCommentsThatWouldStopResolving();

        // Reanchoring is the answer to $atRisk rather than a thing to be
        // stopped by it: it moves every anchor onto the new text and records
        // the ones that genuinely cannot follow.
        if ($command->reanchor) {
            return $this->rerenderAndReanchor($atRisk);
        }

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
     * Re-renders every version and moves its comments' anchors onto the new
     * text, one transaction per version.
     *
     * Per version rather than one transaction for the whole run: the run walks
     * every version in the database, and a single transaction would hold locks
     * across all of them. Per version is still enough to rule out the state
     * that matters — new HTML committed beside anchors still describing the old
     * text, which is worse than either half alone.
     *
     * An anchor that still resolves keeps its quote, prefix and suffix and gains
     * the new offset; those three describe the same passage, only its position
     * moved. One that does not resolve keeps its anchor untouched and is marked
     * orphaned, so a later revision can still try to re-place it.
     */
    private function rerenderAndReanchor(int $atRisk): RefreshDocumentVersionsHtmlResult
    {
        $total = 0;
        $changed = 0;
        $reanchored = 0;
        $orphaned = 0;

        /** @var array<string, list<array{comment_id: string, anchor: Anchor}>> $commentsByVersion */
        $commentsByVersion = [];
        foreach ($this->documentVersions->streamAnchoredCommentsByVersion() as $row) {
            $commentsByVersion[$row['id']][] = [
                'comment_id' => $row['comment_id'],
                'anchor' => new Anchor(
                    $row['anchor_quote'],
                    $row['anchor_prefix'],
                    $row['anchor_suffix'],
                    $row['anchor_offset_hint'],
                ),
            ];
        }

        foreach ($this->documentVersions->streamAllSources() as $row) {
            ++$total;
            $versionId = $row['id'];
            $html = $this->renderer->render($row['markdown_source']);
            $text = DocumentVersion::plainTextOf($html);
            $comments = $commentsByVersion[$versionId] ?? [];

            $this->em->wrapInTransaction(function () use ($versionId, $html, $text, $comments, &$changed, &$reanchored, &$orphaned): void {
                $changed += $this->documentVersions->updateRenderedHtml($versionId, $html);

                foreach ($comments as $comment) {
                    $offset = $this->anchorService->resolve($text, $comment['anchor']);
                    $this->comments->reanchor(
                        $comment['comment_id'],
                        $offset ?? $comment['anchor']->offsetHint,
                        null === $offset,
                    );

                    if (null === $offset) {
                        ++$orphaned;
                    } else {
                        ++$reanchored;
                    }
                }
            });
        }

        return new RefreshDocumentVersionsHtmlResult($total, $changed, $atRisk, false, $reanchored, $orphaned);
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
