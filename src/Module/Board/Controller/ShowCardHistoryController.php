<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowCardHistoryCommand;
use App\Module\Board\Command\ShowCardHistoryHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\BoardAvailability;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The older rows of a card's history, for the frame that replaces the Show older link. */
#[IsGranted(CardVoter::VIEW, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/history',
    name: 'app_board_card_history',
    requirements: ['cardId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowCardHistoryController extends AppController
{
    public function __construct(
        private readonly BoardAvailability $board,
        private readonly ShowCardHistoryHandler $handler,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
        #[MapQueryParameter(options: ['min_range' => 0])] int $offset = 0,
    ): Response {
        $this->board->requireEnabled();

        return $this->render('@Board/_card_history_page.html.twig', [
            'history' => ($this->handler)(new ShowCardHistoryCommand($card, $offset)),
        ]);
    }
}
