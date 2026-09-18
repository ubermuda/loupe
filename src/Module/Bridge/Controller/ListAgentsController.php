<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/agents',
    name: 'app_project_agents',
    methods: ['GET'],
)]
final class ListAgentsController extends AppController
{
    public function __construct(
        private readonly ListAgentsHandler $listAgents,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->listAgents)(new ListAgentsCommand($project));

        return $this->render('@Bridge/list_agents.html.twig', [
            'project' => $view->project,
            'connections' => $view->connections,
        ]);
    }
}
