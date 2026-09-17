<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/board',
    name: 'app_project_board',
    methods: ['GET'],
)]
#[Route(
    '/projects/{id:project}/settings/columns',
    name: 'app_board_settings',
    defaults: ['boardSettings' => true],
    methods: ['GET'],
)]
final class ShowBoardController extends AppController
{
    public function __construct(
        private readonly ShowBoardHandler $showBoard,
        private readonly BoardAvailability $board,
        private readonly ProjectTopicBuilder $topics,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->board->requireEnabled();

        return $this->render($request->attributes->getBoolean('boardSettings') ? '@Board/show_board_settings.html.twig' : '@Board/show_board.html.twig', [
            'board' => ($this->showBoard)(new ShowBoardCommand($project)),
            'addColumnForm' => $this->getInjectedFormView($request, 'addColumnForm'),
            'renameColumnForm' => $this->getInjectedFormView($request, 'renameColumnForm'),
            'boardTopic' => $this->topics->forBoard($project->id ?? throw new \LogicException('Project has no id.')),
        ]);
    }
}
