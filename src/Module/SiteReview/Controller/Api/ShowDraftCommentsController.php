<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\ShowDraftCommentsCommand;
use App\Module\SiteReview\Command\ShowDraftCommentsHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The route path is a public contract the widget depends on — kept stable
 * across the drop of the SiteReview entity.
 */
#[Route(
    '/api/site-review/review',
    name: 'api_site_review_draft_comments',
    methods: ['GET'],
)]
final class ShowDraftCommentsController extends AppController
{
    public function __construct(
        private readonly ShowDraftCommentsHandler $showDraftComments,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $view = ($this->showDraftComments)(new ShowDraftCommentsCommand($project));

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
