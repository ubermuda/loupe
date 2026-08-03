<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces a document's tags with the given set, creating any the project does
 * not have yet.
 *
 * Replace rather than add-and-remove: it is idempotent, and a caller that knows
 * the intended set should not have to diff against the current one.
 *
 * Validation is Tag::normalizeNames()'s job and happens before this touches
 * anything, so a rejected set leaves the document unchanged. Callers that must
 * decide whether to write at all — CreateDocumentHandler — call that method
 * themselves first rather than relying on this one throwing early.
 */
final readonly class SetDocumentTagsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private TagRepository $tags,
    ) {
    }

    /** @return list<Tag> the tags the document now carries, alphabetically */
    public function __invoke(SetDocumentTagsCommand $command): array
    {
        // Throws before the collection below is touched, so a rejected set
        // leaves the document exactly as it was.
        $names = Tag::normalizeNames($command->tagNames);

        $document = $command->document;
        $document->tags->clear();

        $applied = [];
        foreach ($names as $name) {
            $tag = $this->tags->findOrCreate($document->project, $name);
            $document->tags->add($tag);
            $applied[] = $tag;
        }

        $this->em->flush();

        return $applied;
    }
}
