<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Security\AuthenticatedSiteResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/api/site-review/comments',
    name: 'api_site_review_comment_add',
    methods: ['POST'],
)]
final class AddCommentController extends AppController
{
    public function __construct(
        private readonly AddCommentHandler $handler,
        private readonly AuthenticatedSiteResolver $siteResolver,
    ) {
    }

    public function __invoke(#[MapRequestPayload] AddCommentRequest $payload): JsonResponse
    {
        $site = $this->siteResolver->resolve();
        if (null === $site) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $comment = ($this->handler)(new AddCommentCommand(
            site: $site,
            body: trim($payload->body ?? '') ?: throw new \LogicException('body required after validation'),
            selector: $payload->selector,
            text: $payload->text,
            url: trim($payload->url ?? ''),
        ));

        return $this->json(['commentId' => (string) $comment->id], JsonResponse::HTTP_CREATED);
    }
}
