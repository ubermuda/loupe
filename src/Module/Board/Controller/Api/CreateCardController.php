<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Creates a board card from the site-review widget.
 *
 * The path is a Board one on purpose, and `config/packages/security.yaml`
 * grants it to ROLE_API_SITE_REVIEW in its own line rather than folding it under
 * the ^/api/site-review prefix, so a reader of that file sees that a widget
 * token may write to the board.
 */
#[Route(
    '/api/board/cards',
    name: 'api_board_card_create',
    methods: ['POST'],
)]
final class CreateCardController extends AppController
{
    public function __construct(
        private readonly CreateCardHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly BoardAvailability $board,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(#[MapRequestPayload] CreateCardRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        // The board ships off, and a widget holding a cached copy of the script
        // must not reach a feature this instance never switched on.
        $this->board->requireEnabled();

        $title = trim($payload->title ?? '');
        if ('' === $title) {
            throw new \LogicException('title required after validation');
        }

        $card = ($this->handler)(new CreateCardCommand(
            project: $project,
            title: $title,
            body: $payload->body,
            type: $payload->type ?? CardType::Feature,
            priority: $payload->priority ?? CardPriority::Medium,
            // Not Human: nobody authenticated the person who typed this.
            reporter: CardOrigin::Reviewer,
        ));

        return $this->json([
            'cardId' => (string) $card->id,
            'number' => $card->number,
            // The same pair the widget already renders for a resolved marker,
            // so it can show the new card with no second request.
            'label' => \sprintf('#%d %s', $card->number, $card->title),
            'url' => $this->urls->generate(
                'app_board_card',
                ['projectId' => (string) $project->id, 'cardId' => (string) $card->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ], JsonResponse::HTTP_CREATED);
    }
}
