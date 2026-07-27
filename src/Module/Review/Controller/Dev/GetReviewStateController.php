<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Query\DocumentNotFound;
use App\Module\Review\Query\GetReview;
use App\Module\Review\Repository\DocumentRepository;
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
        private readonly DocumentRepository $documents,
    ) {
    }

    public function __invoke(string $documentId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        try {
            $id = Uuid::fromString($documentId);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'invalid id'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // GetReview is project-scoped; resolve the document's project while keeping
        // this dev endpoint owner-scoped for the e2e harness user.
        $document = $this->documents->findOneBy(['id' => $id, 'owner' => $user]);
        if (null === $document) {
            return $this->json(['error' => 'not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $state = ($this->getReview)($id, $document->project);
        } catch (DocumentNotFound) {
            return $this->json(['error' => 'not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($state);
    }
}
