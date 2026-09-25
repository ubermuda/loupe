<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowProjectCardCommand;
use App\Module\Board\Command\ShowProjectCardHandler;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The column a card is in now. The bridge reads it before it resumes a run,
 * and skips the resume when the card left that column. The firewall admits
 * agent-scoped tokens alone.
 */
#[Route(
    '/api/projects/{handle}/board/cards/{cardId}',
    name: 'api_project_board_card_show',
    requirements: ['handle' => '[^/]+', 'cardId' => '[^/]+'],
    methods: ['GET'],
)]
final class ShowProjectCardController extends AppController
{
    public function __construct(
        private readonly ShowProjectCardHandler $showCard,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(string $handle, string $cardId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Card endpoint reached without an authenticated User.');
        }

        if (!$this->board->isEnabled()) {
            return $this->json(['error' => 'board_disabled'], JsonResponse::HTTP_NOT_FOUND);
        }

        $view = ($this->showCard)(new ShowProjectCardCommand($user, $handle, $cardId));
        if (null === $view->project) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (null === $view->card) {
            return $this->json(['error' => 'card_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'cardId' => (string) $view->card->id,
            'number' => $view->card->number,
            'column' => $view->card->column->slug,
        ]);
    }
}
