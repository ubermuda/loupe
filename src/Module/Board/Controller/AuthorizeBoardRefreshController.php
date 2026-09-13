<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\AuthorizeBoardRefreshCommand;
use App\Module\Board\Command\AuthorizeBoardRefreshHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Renews the subscriber cookie of an open board before it reconnects. Another
 * board opened since may have replaced the cookie, and a token expires.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/board/refresh-authorization',
    name: 'app_board_refresh_authorize',
    methods: ['GET'],
)]
final class AuthorizeBoardRefreshController extends AppController
{
    public function __construct(
        private readonly AuthorizeBoardRefreshHandler $authorizeRefresh,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $this->board->requireEnabled();

        if (null === ($this->authorizeRefresh)(new AuthorizeBoardRefreshCommand($project))) {
            throw $this->createNotFoundException('This instance has no hub for a board to listen to.');
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
