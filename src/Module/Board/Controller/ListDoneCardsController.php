<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ListDoneCardsCommand;
use App\Module\Board\Command\ListDoneCardsHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/board/done',
    name: 'app_project_board_done',
    methods: ['GET'],
)]
final class ListDoneCardsController extends AppController
{
    public function __construct(
        private readonly ListDoneCardsHandler $listDoneCards,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->board->requireEnabled();

        $page = max(1, $request->query->getInt('page', 1));
        $view = ($this->listDoneCards)(new ListDoneCardsCommand($project, $page));

        if (null !== $view->clampedPage) {
            return $this->redirectToRoute('app_project_board_done', ['id' => (string) $project->id, 'page' => $view->clampedPage]);
        }

        // `project` is deliberately absent: base.html.twig sets its own from
        // current_project(), and a variable of that name here would be clobbered.
        return $this->render('@Board/list_done_cards.html.twig', [
            'items' => $view->items,
            'total' => $view->total,
            'page' => $page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
        ]);
    }
}
