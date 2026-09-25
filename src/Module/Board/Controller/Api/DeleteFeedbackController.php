<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\DeleteFeedbackCommand;
use App\Module\Board\Command\DeleteFeedbackHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\CommentNotFound;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a pending widget note, and the card it created when that card is
 * still untouched. The answer says whether the card went, so the widget can
 * drop it from its picker.
 */
#[Route(
    '/api/board/feedback/{id}',
    name: 'api_board_feedback_delete',
    methods: ['DELETE'],
)]
final class DeleteFeedbackController extends AppController
{
    public function __construct(
        private readonly DeleteFeedbackHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(string $id): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $commentId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $cardDeleted = ($this->handler)(new DeleteFeedbackCommand($project, $commentId));
        } catch (CommentNotFound) {
            return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        } catch (DomainErrors $error) {
            return $this->json(['error' => $error->errors['board'] ?? throw $error], JsonResponse::HTTP_CONFLICT);
        }

        return $this->json(['cardDeleted' => $cardDeleted]);
    }
}
