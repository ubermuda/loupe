<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * One card as the board page shows it, for a page that heard the card changed.
 *
 * The gate is the board's own gate on the project rather than CardVoter,
 * because a deleted card has no subject to vote on and still owes the page a
 * removal. The card lookup is scoped to the project, so the two agree.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/placement',
    name: 'app_board_card_placement',
    requirements: ['projectId' => Requirement::UUID, 'cardId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowCardPlacementController extends AppController
{
    public function __construct(
        private readonly BoardAvailability $board,
        private readonly ShowCardPlacementHandler $handler,
    ) {
    }

    public function __invoke(
        string $cardId,
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] ?Card $card,
    ): Response {
        $this->board->requireEnabled();

        return new Response(
            $this->renderView('@Board/_card_placement.stream.html.twig', [
                'cardId' => $cardId,
                'placement' => ($this->handler)(new ShowCardPlacementCommand($project, $card)),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
