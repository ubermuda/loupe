<?php

declare(strict_types=1);

namespace App\Module\Review\Query;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\ValueObject\Anchor;

final readonly class GetReview
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private ReviewRepository $reviews,
    ) {
    }

    /**
     * Returns the review state for the current version of an already-authorized document.
     *
     * Comments are grouped into threads: the top-level list contains only root comments (no parent);
     * each root comment carries its direct replies in the `thread` key.
     *
     * Each reported `quote` is widened to whole words (see snapToWordEdges) so a reader
     * doesn't have to guess which sentence a mid-word excerpt came from.
     *
     * @return array{
     *     status: string,
     *     verdict: string|null,
     *     version: int,
     *     comments: list<array{quote: string, body: string, resolved: bool, orphaned: bool, thread: list<array{quote: string, body: string, resolved: bool, orphaned: bool}>}>
     * }
     */
    public function __invoke(Document $document): array
    {
        $currentVersion = $this->documentVersions->findLatest($document);
        $review = $this->reviews->findLatestByVersion($currentVersion);
        $allComments = $this->comments->findByVersion($currentVersion);

        // Index replies by parent id so we can build threads in O(n).
        /** @var array<string, list<Comment>> $repliesByParentId */
        $repliesByParentId = [];
        foreach ($allComments as $comment) {
            if (null !== $comment->parent) {
                $repliesByParentId[(string) $comment->parent->id][] = $comment;
            }
        }

        $threadedComments = [];
        foreach ($allComments as $comment) {
            // Only top-level (parentless) comments appear in the root list.
            if (null !== $comment->parent) {
                continue;
            }

            $commentId = (string) $comment->id;
            $replies = $repliesByParentId[$commentId] ?? [];

            $thread = array_map(
                static fn (Comment $reply) => [
                    'quote' => self::snapToWordEdges($reply->anchor),
                    'body' => $reply->body,
                    'resolved' => $reply->resolved,
                    'orphaned' => $reply->orphaned,
                ],
                $replies,
            );

            $threadedComments[] = [
                'quote' => self::snapToWordEdges($comment->anchor),
                'body' => $comment->body,
                'resolved' => $comment->resolved,
                'orphaned' => $comment->orphaned,
                'thread' => array_values($thread),
            ];
        }

        return [
            'status' => $document->status->value,
            'verdict' => $review?->verdict->value,
            'version' => $currentVersion->versionNumber,
            'comments' => $threadedComments,
        ];
    }

    /**
     * Widens a quote outwards to the nearest whitespace on each side, so a selection
     * that started or ended mid-word is reported as whole words.
     *
     * Reporting only — the stored anchor is untouched, and nothing here feeds
     * AnchorService, so resolution and offsetHint are unaffected. The widening draws
     * on the anchor's own prefix/suffix rather than the document text, which bounds
     * it to the 32 characters of context each carries: a word longer than that window
     * is only partly recovered, and an orphaned comment widens against the context it
     * was captured with rather than the current version's.
     */
    private static function snapToWordEdges(Anchor $anchor): string
    {
        if ('' === $anchor->quote) {
            return '';
        }

        $lead = '';
        // \z, not $ — $ also matches before a trailing newline, which would drag a
        // word across a line break onto a quote that already starts at a line edge.
        if (1 === preg_match('/^\S/u', $anchor->quote) && 1 === preg_match('/\S+\z/u', $anchor->prefix, $before)) {
            $lead = $before[0];
        }

        $trail = '';
        if (1 === preg_match('/\S\z/u', $anchor->quote) && 1 === preg_match('/\A\S+/u', $anchor->suffix, $after)) {
            $trail = $after[0];
        }

        return $lead.$anchor->quote.$trail;
    }
}
