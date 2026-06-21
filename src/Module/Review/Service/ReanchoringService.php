<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DocumentVersion;

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
     * Parent references are remapped: if a comment's parent is also in $openComments, the copy's
     * parent points to the copied parent (old → new mapping). Otherwise, parent is set to null.
     *
     * @param list<Comment> $openComments
     *
     * @return array{carried: int, orphaned: int}
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

        // Resolve parent: if the old parent is among the open comments being copied, map it.
        // Otherwise the parent was resolved (or never open), so the copied reply is detached
        // to a root comment (parent stays null).
        $newParent = null;
        if (null !== $old->parent && \in_array($old->parent, $openComments, true)) {
            $newParent = $this->copyComment($old->parent, $openComments, $oldToNew, $newVersion, $carried, $orphaned);
        }

        // An untargeted comment (empty-quote anchor) has nothing to relocate —
        // carry it forward unchanged; it is never orphaned.
        if ('' === $old->anchor->quote) {
            $copy = new Comment($newVersion, $old->author, $old->body, $old->anchor, $newParent);
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
                \strlen($old->anchor->quote),
            );
            $copy = new Comment($newVersion, $old->author, $old->body, $newAnchor, $newParent);
            ++$carried;
        } else {
            // Quote not found — keep the old anchor data and mark orphaned.
            $copy = new Comment($newVersion, $old->author, $old->body, $old->anchor, $newParent);
            $copy->orphaned = true;
            ++$orphaned;
        }

        // Attach to the version so the Document→versions→comments cascade persists it.
        $newVersion->comments->add($copy);

        $oldToNew[$old] = $copy;

        return $copy;
    }
}
