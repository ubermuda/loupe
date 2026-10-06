<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowBoardStructureCommand;
use App\Module\Board\Command\ShowBoardStructureHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/** The board's columns and lanes with no cards, for a page that heard the structure changed. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/structure',
    name: 'app_board_structure',
    requirements: ['projectId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowBoardStructureController extends AppController
{
    public function __construct(
        private readonly ShowBoardStructureHandler $handler,
    ) {
    }

    public function __invoke(#[MapEntity(id: 'projectId')] Project $project): Response
    {
        return new Response(
            $this->renderView('@Board/_board_structure.stream.html.twig', [
                'board' => ($this->handler)(new ShowBoardStructureCommand($project)),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
