<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\SiteReview\Command\SubmitReviewCommand;
use App\Module\SiteReview\Command\SubmitReviewHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/api/site-review/review/submit',
    name: 'api_site_review_review_submit',
    methods: ['POST'],
)]
final class SubmitReviewController extends AppController
{
    public function __construct(
        private readonly SubmitReviewHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $review = ($this->handler)(new SubmitReviewCommand($project));
        } catch (DomainErrors $e) {
            return $this->json(['errors' => $e->errors], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['reviewId' => (string) $review->id]);
    }
}
