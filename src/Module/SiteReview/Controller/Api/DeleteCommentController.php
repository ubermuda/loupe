<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\DeleteCommentCommand;
use App\Module\SiteReview\Command\DeleteCommentHandler;
use App\Module\SiteReview\Security\AuthenticatedSiteResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/api/site-review/comments/{id}',
    name: 'api_site_review_comment_delete',
    methods: ['DELETE'],
)]
final class DeleteCommentController extends AppController
{
    public function __construct(
        private readonly DeleteCommentHandler $handler,
        private readonly AuthenticatedSiteResolver $siteResolver,
    ) {
    }

    public function __invoke(string $id): JsonResponse
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
            ($this->handler)(new DeleteCommentCommand(
                site: $site,
                commentId: $commentId,
            ));
        } catch (CommentNotFound) {
            return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
