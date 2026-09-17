<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\DocumentRepository;
use App\Search\SearchProviderInterface;
use App\Search\SearchResult;
use App\Search\SearchResults;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class DocumentSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private DocumentRepository $documents,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function search(Project $project, string $query, int $page): SearchResults
    {
        if ('' === $query) {
            return new SearchResults();
        }
        $documents = $this->documents->findPaginatedByProject($project, $page, self::PAGE_SIZE, includeArchived: true, search: $query);
        $items = [];
        foreach ($documents as $document) {
            $items[] = new SearchResult(
                $document->title,
                $this->urls->generate('app_document_review', ['projectId' => (string) $project->id, 'documentId' => (string) $document->id]),
                'document',
            );
        }

        return new SearchResults($items, $page * self::PAGE_SIZE < count($documents));
    }
}
