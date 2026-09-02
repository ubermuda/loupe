<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
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
        private Auditor $auditor,
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

        // A count, not the names: a tag name is a phrase a person typed.
        $this->auditor->record(
            'review.document_tags_updated',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'projectId' => (string) $document->project->id,
                'tagCount' => \count($applied),
            ],
            new AuditSubject('document', (string) $document->id),
        );

        return $applied;
    }
}
