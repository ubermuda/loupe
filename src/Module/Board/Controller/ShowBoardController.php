<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/board',
    name: 'app_project_board',
    methods: ['GET'],
)]
final class ShowBoardController extends AppController
{
    public function __construct(
        private readonly ShowBoardHandler $showBoard,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $this->board->requireEnabled();

        return $this->render('@Board/show_board.html.twig', [
            'board' => ($this->showBoard)(new ShowBoardCommand($project)),
        ]);
    }
}
