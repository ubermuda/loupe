<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardTypeCatalog;
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
        private readonly UrlGeneratorInterface $urls,
        private readonly CardTypeCatalog $catalog,
    ) {
    }

    public function __invoke(#[MapRequestPayload] CreateCardRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $title = trim($payload->title ?? '');
        if ('' === $title) {
            throw new \LogicException('title required after validation');
        }

        $types = $this->catalog->forProject($project->requireId());
        $allowed = $payload->parent ? $types->withChildren() : $types->keys();
        $type = $payload->type ?? ($payload->parent ? ($allowed[0] ?? null) : $types->defaultKey);
        if (null === $type || !\in_array($type, $allowed, true)) {
            return $this->json(['error' => 'unknown_type', 'types' => $allowed], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $card = ($this->handler)(new CreateCardCommand(
            project: $project,
            title: $title,
            body: $payload->body,
            type: $type,
            // Not Human: nobody authenticated the person who typed this.
            reporter: Actor::Reviewer,
            source: new CardSource(CardSourceKind::Widget),
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
