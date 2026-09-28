<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Command\ShowWorkshopInMotionCommand;
use App\Module\Project\Command\ShowWorkshopInMotionHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The In motion section of the Workshop, which the page reloads when a run or a card changes. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/in-motion',
    name: 'app_project_workshop_in_motion',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowWorkshopInMotionController extends AppController
{
    public function __construct(
        private readonly ShowWorkshopInMotionHandler $showInMotion,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->showInMotion)(new ShowWorkshopInMotionCommand($project));

        return $this->render('@Project/_workshop_in_motion.html.twig', [
            'project' => $view->project,
            'inMotion' => $view->inMotion,
        ]);
    }
}
