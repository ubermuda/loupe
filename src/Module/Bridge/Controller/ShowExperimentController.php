<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/experiments/{experiment}',
    name: 'app_project_experiment',
    requirements: ['experiment' => ShowExperimentController::EXPERIMENT_NAME],
    methods: ['GET'],
)]
class ShowExperimentController extends AppController
{
    /** The name rule the bridge applies to an experiment. */
    public const string EXPERIMENT_NAME = '[a-z0-9][a-z0-9_-]{0,63}';

    public function __construct(
        private readonly ShowExperimentHandler $showExperiment,
    ) {
    }

    public function __invoke(Project $project, string $experiment): Response
    {
        $report = ($this->showExperiment)(new ShowExperimentCommand($project, $experiment))
            ?? throw $this->createNotFoundException('No run and no pin name this experiment.');

        return $this->render('@Bridge/show_experiment.html.twig', [
            'project' => $project,
            'report' => $report,
        ]);
    }
}
