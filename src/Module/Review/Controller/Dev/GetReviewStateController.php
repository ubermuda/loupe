<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\GetReviewStateCommand;
use App\Module\Review\Command\GetReviewStateHandler;
use App\Module\Review\Command\ShowReviewCommand;
use App\Module\Review\Command\ShowReviewHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Dev-only endpoint that returns the current review state (including comment quotes)
 * for a document. Used exclusively by Playwright e2e tests — not available in production (When('dev')).
 */
#[Route(
    '/dev/review/{documentId}/state',
    name: 'app_dev_review_state',
    methods: ['GET'],
)]
#[When('dev')]
final class GetReviewStateController extends AppController
{
    public function __construct(
        private readonly ShowReviewHandler $showReview,
        private readonly GetReviewStateHandler $getReviewState,
    ) {
    }

    public function __invoke(string $documentId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        try {
            $id = Uuid::fromString($documentId);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'invalid id'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $view = ($this->getReviewState)(new GetReviewStateCommand($id, $user));
        if (null === $view->document) {
            return $this->json(['error' => 'not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = ($this->showReview)(new ShowReviewCommand($view->document));
        $payload['storedAnchors'] = $view->storedAnchors;

        return $this->json($payload);
    }
}
