<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lists the caller's projects for the bridge CLI's site picker.
 *
 * Account-level tokens only. Project-bound widget tokens (embedded in page
 * HTML, public by design) are rejected with 403 — their contract is "one
 * project, drafts + submit, nothing else"; letting one enumerate the owner's
 * projects would leak the project inventory to any page visitor.
 */
#[Route(
    '/api/site-review/sites',
    name: 'api_site_review_sites',
    methods: ['GET'],
)]
final class ListSitesController extends AppController
{
    public function __construct(
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly ProjectRepository $projects,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        if (null !== $this->projectResolver->resolveWidgetProject()) {
            return $this->json(['error' => 'site_bound_token_not_allowed'], JsonResponse::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Sites endpoint reached without an authenticated User.');
        }

        return $this->json(['sites' => array_values(array_map(
            static fn (Project $project): array => ['id' => (string) $project->id, 'name' => $project->name],
            $this->projects->findByOwner($user),
        ))]);
    }
}
