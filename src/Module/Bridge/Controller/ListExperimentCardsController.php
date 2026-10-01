<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\View\ExperimentCardsQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/experiments/{experiment}/cards',
    name: 'app_project_experiment_cards',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    methods: ['GET'],
)]
class ListExperimentCardsController extends AppController
{
    public function __construct(
        private readonly ShowExperimentHandler $showExperiment,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Project $project, string $experiment, Request $request): Response
    {
        $query = ExperimentCardsQuery::fromQuery($request->query);
        $report = ($this->showExperiment)(new ShowExperimentCommand($project, $experiment, $query->page, $query->variant, $query->leftOutOnly, withMetrics: false))
            ?? throw $this->createNotFoundException('No run and no pin name this experiment.');

        if (null !== $report->clampedPage) {
            $this->logger->info('bridge.experiment_cards_page_clamped', [
                'project' => (string) $project->id,
                'experiment' => $experiment,
                'requestedPage' => $query->page,
                'clampedPage' => $report->clampedPage,
            ]);

            return $this->redirectToRoute('app_project_experiment_cards', [
                'id' => (string) $project->id,
                'experiment' => $experiment,
                ...$query->withPage($report->clampedPage)->routeParams(),
            ]);
        }

        return $this->render('@Bridge/list_experiment_cards.html.twig', [
            'project' => $project,
            'report' => $report,
            'query' => $query,
        ]);
    }
}
