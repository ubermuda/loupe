<?php

declare(strict_types=1);

namespace App\Module\Review\Query;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use Symfony\Component\Uid\Uuid;

final readonly class GetReview
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private ReviewRepository $reviews,
    ) {
    }

    /**
     * Returns the review state for the current version of a document, scoped to the given project.
     *
     * Comments are grouped into threads: the top-level list contains only root comments (no parent);
     * each root comment carries its direct replies in the `thread` key.
     *
     * @return array{
     *     status: string,
     *     verdict: string|null,
     *     version: int,
     *     comments: list<array{quote: string, body: string, resolved: bool, orphaned: bool, thread: list<array{quote: string, body: string, resolved: bool, orphaned: bool}>}>
     * }
     *
     * @throws DocumentNotFound if no document with the given id belongs to $project
     */
    public function __invoke(Uuid $documentId, Project $project): array
    {
        $document = $this->documents->findOneByIdAndProject($documentId, $project);

        if (null === $document) {
            throw DocumentNotFound::forId($documentId);
        }

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
                    'quote' => $reply->anchor->quote,
                    'body' => $reply->body,
                    'resolved' => $reply->resolved,
                    'orphaned' => $reply->orphaned,
                ],
                $replies,
            );

            $threadedComments[] = [
                'quote' => $comment->anchor->quote,
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
}
