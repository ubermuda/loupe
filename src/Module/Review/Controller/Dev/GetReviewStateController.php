<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Query\DocumentNotFound;
use App\Module\Review\Query\GetReview;
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
        private readonly GetReview $getReview,
    ) {
    }

    public function __invoke(string $documentId): JsonResponse
    {
        $user = $this->getUser();
        assert($user instanceof User);

        try {
            $state = ($this->getReview)(Uuid::fromString($documentId), $user);
        } catch (DocumentNotFound) {
            return $this->json(['error' => 'not found'], JsonResponse::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'invalid id'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $this->json($state);
    }
}
