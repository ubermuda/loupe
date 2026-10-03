<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The list view of the board, which the page loads into its frame only while a person shows it. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/list',
    name: 'app_board_list',
    requirements: ['projectId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowBoardListController extends AppController
{
    public function __construct(
        private readonly BoardAvailability $board,
        private readonly ShowBoardHandler $showBoard,
    ) {
    }

    public function __invoke(#[MapEntity(id: 'projectId')] Project $project): Response
    {
        $this->board->requireEnabled();

        return $this->render('@Board/_board_list.html.twig', [
            'board' => ($this->showBoard)(new ShowBoardCommand($project)),
        ]);
    }
}
