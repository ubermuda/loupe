<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ListBacklogCardsCommand;
use App\Module\Board\Command\ListBacklogCardsHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\View\BacklogListQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Every card that waits in the Backlog, which the board does not draw. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/backlog',
    name: 'app_project_board_backlog',
    requirements: ['projectId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ListBacklogCardsController extends AppController
{
    public function __construct(
        private readonly ListBacklogCardsHandler $listBacklogCards,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findBacklogForProjectId(projectId)')] BoardColumn $backlog,
    ): Response {
        $this->board->requireEnabled();

        $listQuery = BacklogListQuery::fromQuery($request->query);
        $view = ($this->listBacklogCards)(new ListBacklogCardsCommand($backlog, $listQuery));

        if (null !== $view->clampedPage) {
            return $this->redirectToRoute('app_project_board_backlog', [
                'projectId' => (string) $project->id,
                ...$listQuery->withPage($view->clampedPage)->routeParams(),
            ]);
        }

        // `project` is deliberately absent: base.html.twig sets its own from
        // current_project(), and a variable of that name here would be clobbered.
        return $this->render('@Board/list_backlog_cards.html.twig', [
            'backlog' => $backlog,
            'view' => $view,
            'listQuery' => $listQuery,
            'types' => CardType::cases(),
        ]);
    }
}
