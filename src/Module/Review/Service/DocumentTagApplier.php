<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\TagRepository;

/**
 * Puts a set of tags on a document, creating any the project does not have yet.
 *
 * Replace rather than add-and-remove: it is idempotent, and a caller that knows
 * the intended set should not have to diff against the current one.
 *
 * It records nothing and it does not flush, so each caller writes the one
 * operation it performed. A document creation that borrowed a tag-update record
 * from a sub-handler said a document was updated before it existed.
 */
final readonly class DocumentTagApplier
{
    public function __construct(
        private TagRepository $tags,
    ) {
    }

    /**
     * @param string[] $tagNames raw names as typed
     *
     * @return list<Tag> the tags the document now carries, alphabetically
     */
    public function apply(Document $document, array $tagNames): array
    {
        // Throws before the collection below is touched, so a rejected set
        // leaves the document exactly as it was.
        $names = Tag::normalizeNames($tagNames);

        $document->tags->clear();

        $applied = [];
        foreach ($names as $name) {
            $tag = $this->tags->findOrCreate($document->project, $name);
            $document->tags->add($tag);
            $applied[] = $tag;
        }

        return $applied;
    }
}
