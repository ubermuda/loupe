<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\AddFeedbackCommand;
use App\Module\Board\Command\AddFeedbackHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\NewStroke;
use App\Module\SiteReview\SiteReviewDrawing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Saves a widget note on its card, and creates the card when the note asks
 * for one. `config/packages/security.yaml` grants the path to the widget token
 * in its own line, next to the card picker's.
 */
#[Route(
    '/api/board/feedback',
    name: 'api_board_feedback_add',
    methods: ['POST'],
)]
final class AddFeedbackController extends AppController
{
    public function __construct(
        private readonly AddFeedbackHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly FeatureFlagService $featureFlags,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(#[MapRequestPayload] AddFeedbackRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        if ([] !== $payload->strokes && !$this->featureFlags->isEnabled(SiteReviewDrawing::FLAG, SiteReviewDrawing::DEFAULT)) {
            return $this->json(['error' => 'drawing_disabled'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $body = trim($payload->body ?? '');
        $target = $payload->target;
        if ('' === $body || null === $target) {
            throw new \LogicException('body and target required after validation');
        }

        $anchors = $payload->newAnchors();
        $strokes = $payload->newStrokes();
        // An anchor-space stroke measures against anchor 0, so with no anchor
        // it can never be drawn again.
        $anchorSpace = array_filter($strokes, static fn (NewStroke $stroke): bool => 'anchor' === $stroke->space);
        if ([] === $anchors && [] !== $anchorSpace) {
            return $this->json(['error' => 'anchor_stroke_without_anchor'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $link = ($this->handler)(new AddFeedbackCommand(
                project: $project,
                body: $body,
                url: trim($payload->url ?? ''),
                anchors: $anchors,
                strokes: $strokes,
                deliveryId: $payload->deliveryId,
                cardId: $target->cardId,
                parentCardId: $target->newCard?->parentCardId,
            ));
        } catch (DomainErrors $error) {
            $field = array_key_first($error->errors);

            // A sub-handler refusal, such as a card write losing a race, carries
            // a translation key the widget cannot name, so it gets a stable one.
            return match ($field) {
                'target' => $this->json(['error' => $error->errors[$field]], JsonResponse::HTTP_UNPROCESSABLE_ENTITY),
                'board', 'deliveryId' => $this->json(['error' => $error->errors[$field]], JsonResponse::HTTP_CONFLICT),
                default => $this->json(['error' => 'feedback_refused'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY),
            };
        }

        $card = $link->card;

        return $this->json([
            'commentId' => (string) $link->comment->id,
            'cardId' => (string) $card->id,
            'number' => $card->number,
            'label' => \sprintf('#%d %s', $card->number, $card->title),
            'url' => $this->urls->generate(
                'app_board_card',
                ['projectId' => (string) $project->id, 'cardId' => (string) $card->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ], JsonResponse::HTTP_CREATED);
    }
}
