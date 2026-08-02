<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\View\DocumentListItem;
use App\Module\Review\View\DocumentListQuery;
use App\Utils\PageList;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/documents',
    name: 'app_project_documents',
    methods: ['GET'],
)]
class ListDocumentsController extends AppController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentVersionRepository $documentVersions,
        private readonly CommentRepository $comments,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $listQuery = DocumentListQuery::fromQuery($request->query);
        $page = $listQuery->page;

        $paginator = $this->documents->findPaginatedByProject($project, $page, self::PER_PAGE, $listQuery->includeArchived);
        $total = count($paginator);

        $clampedPage = PageList::clampedPage($page, $total, self::PER_PAGE);
        if (null !== $clampedPage) {
            $this->logger->info('review.document_list.page_clamped', [
                'project' => (string) $project->id,
                'requestedPage' => $page,
                'clampedPage' => $clampedPage,
            ]);

            return $this->redirectToRoute('app_project_documents', [
                'id' => (string) $project->id,
                ...$listQuery->withPage($clampedPage)->routeParams(),
            ]);
        }

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        $documents = iterator_to_array($paginator, false);
        $latestVersions = $this->documentVersions->findLatestMetaByDocuments($documents);

        $items = array_map(
            function (Document $document) use ($latestVersions): DocumentListItem {
                $meta = $latestVersions[(string) $document->id] ?? throw new \LogicException('Document has no versions.');

                return new DocumentListItem(
                    document: $document,
                    versionNumber: $meta['versionNumber'],
                    updatedAt: $meta['createdAt'],
                    openThreadCount: $this->comments->countOpenByVersion(
                        $this->documentVersions->getReferenceTo($meta['versionId']),
                    ),
                );
            },
            $documents,
        );

        return $this->render('@Review/list_documents.html.twig', [
            'project' => $project,
            'items' => $items,
            'page' => $page,
            'totalPages' => $totalPages,
            'pageList' => PageList::build($page, $totalPages),
            'listQuery' => $listQuery,
            'listParams' => $listQuery->routeParams(),
        ]);
    }
}
