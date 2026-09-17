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
    '/projects/{id:project}/activity/recent',
    name: 'app_project_recent_activity',
    methods: ['GET'],
)]
final class ListRecentActivityController extends AppController
{
    public function __construct(
        private readonly ListActivityHandler $handler,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->handler)(new ListActivityCommand($project, limit: 12));

        return $this->render('outbox/list_recent_activity.html.twig', ['entries' => $view->entries]);
    }
}
