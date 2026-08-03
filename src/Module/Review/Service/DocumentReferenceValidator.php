<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;

/**
 * The rules a set of references must satisfy, shared by document creation and
 * revision so that every entry point gets them — the MCP tools scope ids to the
 * bound project when they resolve them, but the handlers are callable without
 * going through that.
 */
final readonly class DocumentReferenceValidator
{
    /**
     * @param Document|null  $source     the document the references belong to, null when it does not exist yet
     * @param list<Document> $references
     *
     * @return list<Document> the same set with repeated targets collapsed
     */
    public function validated(Project $project, ?Document $source, array $references): array
    {
        $validated = [];

        foreach ($references as $reference) {
            if ($reference->project !== $project) {
                throw new DomainErrors(['references' => 'review.references.error.other_project']);
            }

            // Rejected rather than dropped: a document pointing at itself says
            // nothing, and an author who passed the wrong id learns that here
            // instead of wondering why one reference never appeared.
            if ($reference === $source) {
                throw new DomainErrors(['references' => 'review.references.error.self_reference']);
            }

            // The same target twice is the same link, not a second one.
            if (!\in_array($reference, $validated, true)) {
                $validated[] = $reference;
            }
        }

        return $validated;
    }
}
