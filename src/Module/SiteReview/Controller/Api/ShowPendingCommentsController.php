<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\ShowPendingCommentsCommand;
use App\Module\SiteReview\Command\ShowPendingCommentsHandler;
use App\Module\SiteReview\Context\ContextLabelResolver;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\SiteReviewDrawing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * `/review` rather than `/comments`: the path is a public contract embedded in
 * every deployed widget, so it stays put even though it no longer serves a
 * review batch.
 */
#[Route(
    '/api/site-review/review',
    name: 'api_site_review_pending_comments',
    methods: ['GET'],
)]
final class ShowPendingCommentsController extends AppController
{
    public function __construct(
        private readonly ShowPendingCommentsHandler $showPendingComments,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly FeatureFlagService $featureFlags,
        private readonly ContextLabelResolver $contextLabels,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $view = ($this->showPendingComments)(new ShowPendingCommentsCommand($project));

        // The marker travels from the page rather than from the database,
        // because it describes what this deployment is serving. The widget
        // sends its own, and a marker nothing resolves comes back as null.
        $context = $request->query->get('context');
        $label = $this->contextLabels->resolve(\is_string($context) ? $context : null, $project);

        // The widget carries no flag of its own. Its snippet lives in someone
        // else's page and nobody re-pastes it, so the boot load is the only
        // place the instance can tell it whether drawing is offered.
        return $this->json([
            'drawingEnabled' => $this->featureFlags->isEnabled(SiteReviewDrawing::FLAG, SiteReviewDrawing::DEFAULT),
            'context' => null === $label ? null : ['label' => $label->label, 'url' => $label->url],
            'comments' => array_values(array_map(
                static function (SiteReviewComment $c): array {
                    $anchors = array_values($c->anchors->toArray());
                    $first = $anchors[0] ?? null;

                    // selector/text repeat the first anchor for a widget copy that
                    // predates anchors[]. The script URL carries no version, so a
                    // browser can hold one for a long time, and a widget that reads
                    // no selector renders every comment as an unanchored note.
                    return [
                        'id' => (string) $c->id,
                        'body' => $c->body,
                        'selector' => null === $first ? '' : $first->selector,
                        'text' => null === $first ? '' : $first->text,
                        'url' => $c->url,
                        'anchors' => array_values(array_map(
                            static fn (SiteReviewCommentAnchor $a): array => [
                                'selector' => $a->selector,
                                'text' => $a->text,
                                'quote' => $a->quote,
                                'quotePrefix' => $a->quotePrefix,
                                'quoteSuffix' => $a->quoteSuffix,
                            ],
                            $anchors,
                        )),
                        'strokes' => $c->strokes ?? [],
                    ];
                },
                $view->comments,
            ))]);
    }
}
