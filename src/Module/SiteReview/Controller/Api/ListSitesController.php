<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Repository\SiteRepository;
use App\Module\SiteReview\Security\AuthenticatedSiteResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lists the caller's sites for the bridge CLI's site picker.
 *
 * Account-level tokens only. Site-bound widget tokens (embedded in page HTML,
 * public by design) are rejected with 403 — their contract is "one site,
 * drafts + submit, nothing else"; letting one enumerate the owner's sites
 * would leak the site inventory to any page visitor.
 */
#[Route(
    '/api/site-review/sites',
    name: 'api_site_review_sites',
    methods: ['GET'],
)]
final class ListSitesController extends AppController
{
    public function __construct(
        private readonly AuthenticatedSiteResolver $siteResolver,
        private readonly SiteRepository $sites,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        if (null !== $this->siteResolver->resolve()) {
            return $this->json(['error' => 'site_bound_token_not_allowed'], JsonResponse::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Sites endpoint reached without an authenticated User.');
        }

        return $this->json(['sites' => array_values(array_map(
            static fn (Site $site): array => ['id' => (string) $site->id, 'name' => $site->name],
            $this->sites->findByOwner($user),
        ))]);
    }
}
