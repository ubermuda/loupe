<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ListWorkerRunsCommand;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\WorkerRunListQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs',
    name: 'app_project_worker_runs',
    methods: ['GET'],
)]
class ListWorkerRunsController extends AppController
{
    public function __construct(
        private readonly ListWorkerRunsHandler $listWorkerRuns,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $listQuery = WorkerRunListQuery::fromQuery($request->query);
        $view = ($this->listWorkerRuns)(new ListWorkerRunsCommand($project, $listQuery));

        if (null !== $view->clampedPage) {
            $this->logger->info('bridge.worker_run_list_page_clamped', [
                'project' => (string) $project->id,
                'requestedPage' => $listQuery->page,
                'clampedPage' => $view->clampedPage,
            ]);

            return $this->redirectToRoute('app_project_worker_runs', [
                'id' => (string) $project->id,
                ...$listQuery->withPage($view->clampedPage)->routeParams(),
            ]);
        }

        return $this->render('@Bridge/list_worker_runs.html.twig', [
            'project' => $project,
            'items' => $view->items,
            'filteredTotal' => $view->filteredTotal,
            'page' => $listQuery->page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
            'listQuery' => $listQuery,
            'states' => WorkerRunState::cases(),
            'bridgeIds' => $view->bridgeIds,
        ]);
    }
}
