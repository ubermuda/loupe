<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use App\Module\SiteReview\Security\AuthenticatedSiteResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/api/site-review/review',
    name: 'api_site_review_current_review',
    methods: ['GET'],
)]
final class CurrentReviewController extends AppController
{
    public function __construct(
        private readonly SiteReviewRepository $siteReviews,
        private readonly AuthenticatedSiteResolver $siteResolver,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $site = $this->siteResolver->resolve();
        if (null === $site) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $review = $this->siteReviews->findOneInProgress($site);
        if (null === $review) {
            return $this->json(['review' => null]);
        }

        return $this->json(['review' => [
            'id' => (string) $review->id,
            'createdAt' => $review->createdAt->format(\DateTimeInterface::ATOM),
            'comments' => array_values(array_map(
                static fn (SiteReviewComment $c): array => [
                    'id' => (string) $c->id,
                    'body' => $c->body,
                    'selector' => $c->selector,
                    'text' => $c->text,
                    'url' => $c->url,
                ],
                $review->comments->toArray(),
            )),
        ]]);
    }
}
