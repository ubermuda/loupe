<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller;

use App\Controller\AppController;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Workflow\Command\ShowWorkflowSettingsCommand;
use App\Module\Workflow\Command\ShowWorkflowSettingsHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/workflow',
    name: 'app_project_workflow',
    methods: ['GET'],
)]
final class ShowWorkflowSettingsController extends AppController
{
    public function __construct(
        private readonly ShowWorkflowSettingsHandler $showWorkflowSettings,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $this->board->requireEnabled();

        $view = ($this->showWorkflowSettings)(new ShowWorkflowSettingsCommand($project));

        return $this->render('@Workflow/show_workflow_settings.html.twig', [
            'project' => $view->project,
            'template' => $view->template,
        ]);
    }
}
