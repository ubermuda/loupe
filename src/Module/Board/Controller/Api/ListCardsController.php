<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Board\Command\SearchCardsCommand;
use App\Module\Board\Command\SearchCardsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lists the project's open cards for the widget's picker.
 *
 * This publishes card titles to any holder of the widget token, which is
 * everyone who can read an instrumented page's source. That is accepted rather
 * than overlooked; the Loupe design 'Picking and creating cards from the
 * site-review widget' records the decision and what it costs.
 */
#[Route(
    '/api/board/cards',
    name: 'api_board_card_list',
    methods: ['GET'],
)]
final class ListCardsController extends AppController
{
    /** One page of cards. A picker is for choosing, not for browsing a backlog. */
    private const int LIMIT = 20;

    public function __construct(
        private readonly SearchCardsHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $this->board->requireEnabled();

        $query = $request->query->get('q');
        $view = ($this->handler)(new SearchCardsCommand(
            $project,
            \is_string($query) ? trim($query) : '',
            self::LIMIT,
        ));

        return $this->json(['cards' => array_map(
            static fn (Card $card): array => [
                'cardId' => (string) $card->id,
                'number' => $card->number,
                'title' => $card->title,
                'status' => $card->status->value,
            ],
            $view->cards,
        )]);
    }
}
