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
        $paginator = $this->documents->findPaginatedByProject(
            $command->project,
            $listQuery->page,
            self::PER_PAGE,
            $listQuery->includeArchived,
            $listQuery->search,
            $listQuery->status,
            $listQuery->tagName,
            $listQuery->seriesName,
        );
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

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
                    );
                },
                $documents,
            ),
            filteredTotal: $total,
            totalPages: $totalPages,
            pageList: PageList::build($listQuery->page, $totalPages),
            projectTags: $this->tags->findByProject($command->project),
            projectSeries: $this->series->findByProject($command->project),
            clampedPage: PageList::clampedPage($listQuery->page, $total, self::PER_PAGE),
        );
    }
}
