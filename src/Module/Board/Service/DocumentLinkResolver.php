<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;

/**
 * Resolves document ids to the project's documents, refusing anything else.
 *
 * Unlike a pull request URL, which is kept as given because a self-hosted forge
 * is a legitimate answer nothing can verify, a document id either names a
 * document of this project or it is wrong.
 *
 * Called before a handler opens its transaction, never inside one. A
 * DomainErrors thrown inside wrapInTransaction rolls it back and closes the
 * EntityManager, so the handler could do nothing afterwards. The same reason
 * puts the pull request URL length check where it is.
 */
final readonly class DocumentLinkResolver
{
    public function __construct(
        private DocumentRepository $documents,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Document>
     */
    public function resolve(Project $project, array $ids): array
    {
        $documents = [];
        $seen = [];
        foreach ($ids as $id) {
            $id = trim($id);
            if ('' === $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            // Scoped to the project rather than looked up and checked after,
            // so a document of somebody else\'s project is indistinguishable
            // from one that does not exist.
            $document = $this->documents->findOneByIdAndProjectId($id, (string) $project->id);
            if (null === $document) {
                throw new DomainErrors(['documentIds' => 'board.card.error.document_unknown']);
            }

            $documents[] = $document;
        }

        return $documents;
    }
}
