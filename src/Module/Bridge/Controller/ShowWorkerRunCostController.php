<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowWorkerRunCostCommand;
use App\Module\Bridge\Command\ShowWorkerRunCostHandler;
use App\Module\Bridge\ValueObject\CostRange;
use App\Module\Bridge\ValueObject\CostSplit;
use App\Module\Bridge\View\WorkerRunCostQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/cost',
    name: 'app_project_worker_run_cost',
    methods: ['GET'],
)]
class ShowWorkerRunCostController extends AppController
{
    public function __construct(
        private readonly ShowWorkerRunCostHandler $showWorkerRunCost,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $view = ($this->showWorkerRunCost)(new ShowWorkerRunCostCommand($project, WorkerRunCostQuery::fromQuery($request->query)));

        return $this->render('@Bridge/show_worker_run_cost.html.twig', [
            'project' => $view->project,
            'cost' => $view,
            'ranges' => CostRange::cases(),
            'splits' => CostSplit::cases(),
        ]);
    }
}
