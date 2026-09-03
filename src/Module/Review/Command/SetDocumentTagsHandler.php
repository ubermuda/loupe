<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Service\DocumentTagApplier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces a document's tags with the given set, creating any the project does
 * not have yet.
 *
 * Replace rather than add-and-remove: it is idempotent, and a caller that knows
 * the intended set should not have to diff against the current one.
 *
 * DocumentTagApplier holds the applying, so a caller that writes tags as part
 * of a larger operation records that operation instead of this one.
 */
final readonly class SetDocumentTagsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentTagApplier $tagApplier,
        private Auditor $auditor,
    ) {
    }

    /** @return list<Tag> the tags the document now carries, alphabetically */
    public function __invoke(SetDocumentTagsCommand $command): array
    {
        $document = $command->document;
        $applied = $this->tagApplier->apply($document, $command->tagNames);

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
