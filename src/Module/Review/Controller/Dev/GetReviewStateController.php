<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Query\GetReview;
use App\Module\Review\Repository\CommentRepository;
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
        private readonly CommentRepository $comments,
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

        // Owner-scoped for the e2e harness user.
        $document = $this->documents->findOneBy(['id' => $id, 'owner' => $user]);
        if (null === $document) {
            return $this->json(['error' => 'not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = ($this->getReview)($document);
        $payload['storedAnchors'] = $this->storedAnchors($document);

        return $this->json($payload);
    }

    /**
     * The anchor fields exactly as captured, alongside GetReview's own payload.
     *
     * GetReview reports a quote widened to word edges, which is right for an
     * agent and useless for proving what the browser actually stored: a
     * corrupted prefix or suffix does not change the widened quote at all. That
     * is not hypothetical — form `trim` silently stripped the boundary
     * whitespace off every captured prefix and suffix until 2026-08-02, making
     * context disambiguation score zero for any selection next to a space, and
     * the whole unit suite stayed green throughout.
     *
     * Dev-only and deliberately not added to GetReview: the agent-facing payload
     * should not grow fields that exist to be asserted on.
     *
     * @return list<array{quote: string, prefix: string, suffix: string}>
     */
    private function storedAnchors(Document $document): array
    {
        $anchors = [];
        foreach ($this->comments->findByVersion($document->currentVersion()) as $comment) {
            $anchors[] = [
                'quote' => $comment->anchor->quote,
                'prefix' => $comment->anchor->prefix,
                'suffix' => $comment->anchor->suffix,
            ];
        }

        return $anchors;
    }
}
