<?php

declare(strict_types=1);

namespace App\Outbox\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Outbox\ActivityFamily;
use App\Outbox\Command\ListActivityPageCommand;
use App\Outbox\Command\ListActivityPageHandler;
use App\Outbox\View\ActivityListQuery;
use App\Session\ReadOnlyAwareSessionHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/activity',
    name: 'app_project_activity',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => ['activity-frame', 'activity-count']],
    methods: ['GET'],
)]
final class ListActivityController extends AppController
{
    public function __construct(
        private readonly ListActivityPageHandler $listActivity,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $listQuery = ActivityListQuery::fromQuery($request->query);
        $view = ($this->listActivity)(new ListActivityPageCommand($project, $listQuery));

        if (null !== $view->clampedPage) {
            $this->logger->info('outbox.activity_list_page_clamped', [
                'project' => (string) $project->id,
                'requestedPage' => $listQuery->page,
                'clampedPage' => $view->clampedPage,
            ]);

            return $this->redirectToRoute('app_project_activity', [
                'id' => (string) $project->id,
                ...$listQuery->withPage($view->clampedPage)->routeParams(),
            ]);
        }

        return $this->render('outbox/list_activity.html.twig', [
            'project' => $project,
            'entries' => $view->entries,
            'filteredTotal' => $view->filteredTotal,
            'page' => $listQuery->page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
            'listQuery' => $listQuery,
            'families' => ActivityFamily::cases(),
        ]);
    }
}
