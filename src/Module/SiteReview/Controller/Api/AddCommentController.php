<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Command\NewStroke;
use App\Module\SiteReview\SiteReviewDrawing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

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
        private readonly FeatureFlagService $featureFlags,
    ) {
    }

    public function __invoke(#[MapRequestPayload] AddCommentRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        // Refused rather than dropped. A widget holding a cached copy from
        // before the flag went off would otherwise get a 201 for a comment
        // whose drawing never reached the database.
        if ([] !== $payload->strokes && !$this->featureFlags->isEnabled(SiteReviewDrawing::FLAG, SiteReviewDrawing::DEFAULT)) {
            return $this->json(['error' => 'drawing_disabled'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $body = trim($payload->body ?? '');
        if ('' === $body) {
            throw new \LogicException('body required after validation');
        }

        $anchors = $payload->newAnchors();
        $strokes = $payload->newStrokes();

        // An anchor-space stroke measures against anchor 0, so with no anchor
        // it can never be drawn again. The widget picks the space from the
        // anchors it is saving, so only another client reaches this.
        $anchorSpace = array_filter($strokes, static fn (NewStroke $stroke): bool => 'anchor' === $stroke->space);
        if ([] === $anchors && [] !== $anchorSpace) {
            return $this->json(['error' => 'anchor_stroke_without_anchor'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $comment = ($this->handler)(new AddCommentCommand(
                project: $project,
                body: $body,
                url: trim($payload->url ?? ''),
                anchors: $anchors,
                strokes: $strokes,
                context: $payload->context(),
                deliveryId: $payload->deliveryId,
            ));
        } catch (DomainErrors $error) {
            if (!isset($error->errors['deliveryId'])) {
                throw $error;
            }

            return $this->json(['error' => $error->errors['deliveryId']], JsonResponse::HTTP_CONFLICT);
        }

        return $this->json(['commentId' => (string) $comment->id], JsonResponse::HTTP_CREATED);
    }
}
