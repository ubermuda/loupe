<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
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
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(#[MapRequestPayload] AddCommentRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $comment = ($this->handler)(new AddCommentCommand(
            project: $project,
            body: trim($payload->body ?? '') ?: throw new \LogicException('body required after validation'),
            selector: $payload->selector,
            text: $payload->text,
            url: trim($payload->url ?? ''),
        ));

        return $this->json(['commentId' => (string) $comment->id], JsonResponse::HTTP_CREATED);
    }
}
