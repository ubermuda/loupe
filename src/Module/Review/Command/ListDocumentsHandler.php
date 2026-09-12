<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\SeriesRepository;
use App\Module\Review\Repository\TagRepository;
use App\Module\Review\ValueObject\CommentSignals;
use App\Module\Review\View\DocumentListItem;
use App\Utils\PageList;

final readonly class ListDocumentsHandler
{
    public const int PER_PAGE = 20;

    /**
     * The ceiling on a caller-chosen page size. The web list does not choose
     * one; document_list does, and an agent may ask for any number.
     */
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private DocumentRepository $documents,
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private TagRepository $tags,
        private SeriesRepository $series,
    ) {
    }

    public function __invoke(ListDocumentsCommand $command): ListDocumentsView
    {
        $listQuery = $command->listQuery;
        // Clamped rather than refused: an out-of-range page size should be
        // brought into range, not fail the call.
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        $paginator = $this->documents->findPaginatedByProject(
            $command->project,
            $listQuery->page,
            $perPage,
            $listQuery->includeArchived,
            $listQuery->search,
            $listQuery->status,
            $listQuery->tagName,
            $listQuery->seriesName,
        );
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / $perPage));

        $documents = iterator_to_array($paginator, false);
        $this->documents->preloadTags($documents);
        $latestVersions = $this->documentVersions->findLatestMetaByDocuments($documents);

        // One grouped query for the page rather than one per row, matching how
        // tags and version metadata above are already loaded.
        $signals = $this->comments->signalsByVersions(array_values(array_map(
            static fn (array $meta): string => (string) $meta['versionId'],
            $latestVersions,
        )));

        return new ListDocumentsView(
            items: array_map(
                static function (Document $document) use ($latestVersions, $signals): DocumentListItem {
                    $meta = $latestVersions[(string) $document->id] ?? throw new \LogicException('Document has no versions.');

                    return new DocumentListItem(
                        document: $document,
                        versionNumber: $meta['versionNumber'],
                        updatedAt: $meta['createdAt'],
                        signals: $signals[(string) $meta['versionId']] ?? new CommentSignals(),
                        description: $meta['description'],
                    );
                },
                $documents,
            ),
            filteredTotal: $total,
            totalPages: $totalPages,
            pageList: PageList::build($listQuery->page, $totalPages),
            projectTags: $this->tags->findByProject($command->project),
            projectSeries: $this->series->findByProject($command->project),
            clampedPage: PageList::clampedPage($listQuery->page, $total, $perPage),
        );
    }
}
