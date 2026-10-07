<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowBoardManifestCommand;
use App\Module\Board\Command\ShowBoardManifestHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The digest of each card and of the frame, for a board page that lost its live connection. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/manifest',
    name: 'app_board_manifest',
    requirements: ['projectId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowBoardManifestController extends AppController
{
    public function __construct(
        private readonly ShowBoardManifestHandler $handler,
    ) {
    }

    public function __invoke(#[MapEntity(id: 'projectId')] Project $project): JsonResponse
    {
        $manifest = ($this->handler)(new ShowBoardManifestCommand($project));

        return $this->json([
            'cards' => $manifest->cards,
            'structure' => $manifest->structure,
            'terminalTotals' => $manifest->terminalTotals,
            'backlogCount' => $manifest->backlogCount,
        ]);
    }
}
