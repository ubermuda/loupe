<?php

declare(strict_types=1);

namespace App\Outbox\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Outbox\Command\ListActivityCommand;
use App\Outbox\Command\ListActivityHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/activity',
    name: 'app_project_activity',
    methods: ['GET'],
)]
final class ListActivityController extends AppController
{
    public function __construct(
        private readonly ListActivityHandler $listActivity,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->listActivity)(new ListActivityCommand($project));

        return $this->render('outbox/list_activity.html.twig', [
            'project' => $view->project,
            'entries' => $view->entries,
            'pageSize' => $view->pageSize,
        ]);
    }
}
