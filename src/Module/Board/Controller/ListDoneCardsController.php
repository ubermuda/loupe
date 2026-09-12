<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ListDoneCardsCommand;
use App\Module\Board\Command\ListDoneCardsHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The whole history of one terminal column. */
#[IsGranted(ProjectVoter::VIEW, subject: new Expression('args["column"].project'))]
#[Route(
    '/projects/{projectId}/board/done/{columnId}',
    name: 'app_project_board_done',
    requirements: ['columnId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ListDoneCardsController extends AppController
{
    public function __construct(
        private readonly ListDoneCardsHandler $listDoneCards,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(columnId, projectId)')] BoardColumn $column,
    ): Response {
        $this->board->requireEnabled();

        if (!$column->terminal) {
            throw $this->createNotFoundException();
        }

        $page = max(1, $request->query->getInt('page', 1));
        $view = ($this->listDoneCards)(new ListDoneCardsCommand($column, $page));

        $routeParams = ['projectId' => (string) $column->project->id, 'columnId' => (string) $column->id];
        if (null !== $view->clampedPage) {
            return $this->redirectToRoute('app_project_board_done', [...$routeParams, 'page' => $view->clampedPage]);
        }

        // `project` is deliberately absent: base.html.twig sets its own from
        // current_project(), and a variable of that name here would be clobbered.
        return $this->render('@Board/list_done_cards.html.twig', [
            'column' => $column,
            'routeParams' => $routeParams,
            'items' => $view->items,
            'total' => $view->total,
            'page' => $page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
        ]);
    }
}
