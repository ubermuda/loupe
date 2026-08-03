<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
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
 * Every name is validated before anything is persisted, and the single flush is
 * last. CreateDocumentHandler relies on both: it hands over a document that is
 * persisted but unflushed, so a rejected name leaves no row behind.
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
        $names = [];
        foreach ($command->tagNames as $rawName) {
            $name = Tag::normalizeName($rawName);

            if ('' === $name) {
                continue;
            }

            if (mb_strlen($name) > Tag::MAX_NAME_LENGTH) {
                throw new DomainErrors(['tags' => 'review.tags.error.too_long']);
            }

            if (!\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        sort($names);

        $document = $command->document;
        $document->tags->clear();

        $applied = [];
        foreach ($names as $name) {
            $tag = $this->tags->findOneByProjectAndName($document->project, $name)
                ?? new Tag($document->project, $name);

            if (null === $tag->id) {
                $this->em->persist($tag);
            }

            $document->tags->add($tag);
            $applied[] = $tag;
        }

        $this->em->flush();

        return $applied;
    }
}
