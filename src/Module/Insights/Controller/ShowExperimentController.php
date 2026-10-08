<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/experiments/{experiment}',
    name: 'app_project_analytics_experiment',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    methods: ['GET'],
)]
class ShowExperimentController extends AppController
{
    public function __construct(
        private readonly ShowExperimentHandler $showExperiment,
    ) {
    }

    public function __invoke(Project $project, string $experiment): Response
    {
        $report = ($this->showExperiment)(new ShowExperimentCommand($project, $experiment))
            ?? throw $this->createNotFoundException('No run and no pin name this experiment.');

        return $this->render('@Insights/show_experiment.html.twig', [
            'project' => $project,
            'report' => $report,
        ]);
    }
}
