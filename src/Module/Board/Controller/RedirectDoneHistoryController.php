<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The history URL from before board columns, kept so a saved link still lands.
 * The page it redirects to checks the board flag.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/done',
    name: 'app_project_board_done_redirect',
    requirements: ['projectId' => Requirement::UUID],
    methods: ['GET'],
)]
final class RedirectDoneHistoryController extends AppController
{
    public function __invoke(
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findFirstTerminalForProjectId(projectId)')] BoardColumn $column,
    ): Response {
        // Temporary, because the board's first terminal column can change.
        return $this->redirectToRoute('app_project_board_terminal_column', [
            'projectId' => (string) $project->id,
            'columnId' => (string) $column->id,
        ]);
    }
}
