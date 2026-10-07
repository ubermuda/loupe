<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ListExperimentsCommand;
use App\Module\Bridge\Command\ListExperimentsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/experiments',
    name: 'app_project_analytics_experiments',
    methods: ['GET'],
)]
class ListExperimentsController extends AppController
{
    public function __construct(
        private readonly ListExperimentsHandler $listExperiments,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('@Insights/list_experiments.html.twig', [
            'project' => $project,
            'experiments' => ($this->listExperiments)(new ListExperimentsCommand($project))->experiments,
        ]);
    }
}
