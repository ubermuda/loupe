<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DocumentVersion;

/**
 * @phpstan-type ReanchoringSummary array{carried: int, orphaned: int}
 */
final readonly class ReanchoringService
{
    public function __construct(
        private AnchorService $anchorService,
    ) {
    }

    /**
     * Copies each open comment onto $newVersion, resolving its anchor against the new rendered HTML.
     * Comments whose quote still appears are "carried"; those that don't are "orphaned".
     *
     * Parent references are remapped onto the copies: a reply's copy points at its parent's copy,
     * and a root's copy keeps a null parent. Nothing outside $openComments is ever copied.
     *
     * A copy inherits the original's createdAt. It is the same comment carried forward, not a new
     * one, so its age must keep counting from when it was written rather than restart at every
     * revision.
     *
     * @param list<Comment> $openComments
     *
     * @return ReanchoringSummary
     */
    public function reanchor(array $openComments, DocumentVersion $newVersion): array
    {
        $carried = 0;
        $orphaned = 0;

        /** @var \SplObjectStorage<Comment, Comment> $oldToNew Maps old Comment → new Comment */
        $oldToNew = new \SplObjectStorage();

        // We need to copy parents before children. Build a copy in dependency order.
        // First pass: copy all comments (handling parent ordering via recursive resolution).
        foreach ($openComments as $old) {
            $this->copyComment($old, $openComments, $oldToNew, $newVersion, $carried, $orphaned);
        }

        return ['carried' => $carried, 'orphaned' => $orphaned];
    }

    /**
     * @param list<Comment>                       $openComments
     * @param \SplObjectStorage<Comment, Comment> $oldToNew
     */
    private function copyComment(
        Comment $old,
        array $openComments,
        \SplObjectStorage $oldToNew,
        DocumentVersion $newVersion,
        int &$carried,
        int &$orphaned,
    ): Comment {
        // Already copied — return the existing copy.
        if ($oldToNew->offsetExists($old)) {
            return $oldToNew[$old];
        }

        // Map the parent onto its copy. The set handed in is thread-complete — a reply is open
        // only while its root is — and the in_array guard keeps the copy inside it: a parent
        // outside the set is left behind rather than dragged onto the new version.
        $newParent = null;
        if (null !== $old->parent && \in_array($old->parent, $openComments, true)) {
            $newParent = $this->copyComment($old->parent, $openComments, $oldToNew, $newVersion, $carried, $orphaned);
        }

        // An untargeted comment (empty-quote anchor) has nothing to relocate —
        // carry it forward unchanged; it is never orphaned.
        if ('' === $old->anchor->quote) {
            $copy = new Comment($newVersion, $old->author, $old->body, $old->anchor, $newParent, $old->replacement, $old->createdAt);
            // An addressed thread carries its status onto the copy: the agent's
            // claim that it acted survives the revision, only the human clears it.
            $copy->status = $old->status;
            $newVersion->comments->add($copy);
            $oldToNew[$old] = $copy;
            ++$carried;

            return $copy;
        }

        // Resolve the old anchor quote against the new version's plain text
        // (same basis as at add-time: strip_tags then html_entity_decode).
        $newPlain = $newVersion->plainText();
        $resolvedOffset = $this->anchorService->resolve($newPlain, $old->anchor);

        if (null !== $resolvedOffset) {
            // Quote found in new text — build a fresh anchor at the new offset.
            $newAnchor = $this->anchorService->create(
                $newPlain,
                $resolvedOffset,
                mb_strlen($old->anchor->quote, 'UTF-8'),
            );
            $copy = new Comment($newVersion, $old->author, $old->body, $newAnchor, $newParent, $old->replacement, $old->createdAt);
            ++$carried;
        } else {
            // Quote not found — keep the old anchor data and mark orphaned.
            $copy = new Comment($newVersion, $old->author, $old->body, $old->anchor, $newParent, $old->replacement, $old->createdAt);
            $copy->orphaned = true;
            ++$orphaned;
        }

        $copy->status = $old->status;

        // Attach to the version so the Document→versions→comments cascade persists it.
        $newVersion->comments->add($copy);

        $oldToNew[$old] = $copy;

        return $copy;
    }
}
