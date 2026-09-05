<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\GetReviewStateCommand;
use App\Module\Review\Command\GetReviewStateHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Dev-only endpoint that revises a document and returns the revision summary.
 * Used exclusively by Playwright e2e tests — not available in production (When('dev')).
 */
#[Route(
    '/dev/review/{documentId}/revise',
    name: 'app_dev_review_revise',
    methods: ['POST'],
)]
#[When('dev')]
final class ReviseDocumentController extends AppController
{
    public function __construct(
        private readonly GetReviewStateHandler $getReviewState,
        private readonly ReviseDocumentHandler $reviseDocument,
    ) {
    }

    public function __invoke(Request $request, string $documentId): JsonResponse
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

        $summary = ($this->reviseDocument)(new ReviseDocumentCommand(
            $view->document,
            $request->request->getString('markdown'),
            $request->request->getString('description', 'E2E revision.'),
        ));

        return $this->json($summary);
    }
}
