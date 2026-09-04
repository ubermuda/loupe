<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Command\NewAnchor;
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

        $body = trim($payload->body ?? '');
        if ('' === $body) {
            throw new \LogicException('body required after validation');
        }

        $comment = ($this->handler)(new AddCommentCommand(
            project: $project,
            body: $body,
            url: trim($payload->url ?? ''),
            anchors: $this->anchorsOf($payload),
        ));

        return $this->json(['commentId' => (string) $comment->id], JsonResponse::HTTP_CREATED);
    }

    /**
     * A body that carries neither anchors[] nor a selector is a page note.
     *
     * @return list<NewAnchor>
     */
    private function anchorsOf(AddCommentRequest $payload): array
    {
        if ([] === $payload->anchors) {
            return '' === $payload->selector ? [] : [new NewAnchor($payload->selector, $payload->text)];
        }

        return array_values(array_map(
            static fn (SiteReviewAnchorInput $anchor): NewAnchor => new NewAnchor(
                selector: $anchor->selector ?? '',
                text: $anchor->text,
                quote: $anchor->quote,
                quotePrefix: $anchor->quotePrefix,
                quoteSuffix: $anchor->quoteSuffix,
            ),
            $payload->anchors,
        ));
    }
}
