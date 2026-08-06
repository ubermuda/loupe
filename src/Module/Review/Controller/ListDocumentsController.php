<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Command\ListDocumentsCommand;
use App\Module\Review\Command\ListDocumentsHandler;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\View\DocumentListQuery;
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
    public function __construct(
        private readonly ListDocumentsHandler $listDocuments,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $listQuery = DocumentListQuery::fromQuery($request->query);
        $view = ($this->listDocuments)(new ListDocumentsCommand($project, $listQuery));

        if (null !== $view->clampedPage) {
            $this->logger->info('review.document_list.page_clamped', [
                'project' => (string) $project->id,
                'requestedPage' => $listQuery->page,
                'clampedPage' => $view->clampedPage,
            ]);

            return $this->redirectToRoute('app_project_documents', [
                'id' => (string) $project->id,
                ...$listQuery->withPage($view->clampedPage)->routeParams(),
            ]);
        }

        return $this->render('@Review/list_documents.html.twig', [
            'project' => $project,
            'items' => $view->items,
            'page' => $listQuery->page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
            'listQuery' => $listQuery,
            'statuses' => DocumentStatus::cases(),
            'projectTags' => $view->projectTags,
        ]);
    }
}
