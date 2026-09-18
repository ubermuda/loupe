<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Command\ShowWorkshopCommand;
use App\Module\Project\Command\ShowWorkshopHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}',
    name: 'app_project_workshop',
    methods: ['GET'],
)]
final class ShowWorkshopController extends AppController
{
    public function __construct(
        private readonly ShowWorkshopHandler $showWorkshop,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('@Project/show_workshop.html.twig', [
            'workshop' => ($this->showWorkshop)(new ShowWorkshopCommand($project)),
        ]);
    }
}
