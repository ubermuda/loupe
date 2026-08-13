<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\ShowPendingCommentsCommand;
use App\Module\SiteReview\Command\ShowPendingCommentsHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

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
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $view = ($this->showPendingComments)(new ShowPendingCommentsCommand($project));

        return $this->json(['comments' => array_values(array_map(
            static fn (SiteReviewComment $c): array => [
                'id' => (string) $c->id,
                'body' => $c->body,
                'selector' => $c->selector,
                'text' => $c->text,
                'url' => $c->url,
            ],
            $view->comments,
        ))]);
    }
}
