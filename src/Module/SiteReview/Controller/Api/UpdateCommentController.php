<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\UpdateCommentCommand;
use App\Module\SiteReview\Command\UpdateCommentHandler;
use App\Module\SiteReview\Security\AuthenticatedSiteResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/api/site-review/comments/{id}',
    name: 'api_site_review_comment_update',
    methods: ['PATCH'],
)]
final class UpdateCommentController extends AppController
{
    public function __construct(
        private readonly UpdateCommentHandler $handler,
        private readonly AuthenticatedSiteResolver $siteResolver,
    ) {
    }

    public function __invoke(string $id, #[MapRequestPayload] UpdateCommentRequest $payload): JsonResponse
    {
        $site = $this->siteResolver->resolve();
        if (null === $site) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $commentId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $comment = ($this->handler)(new UpdateCommentCommand(
                site: $site,
                commentId: $commentId,
                body: trim($payload->body ?? '') ?: throw new \LogicException('body required after validation'),
            ));
        } catch (CommentNotFound) {
            return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['commentId' => (string) $comment->id]);
    }
}
