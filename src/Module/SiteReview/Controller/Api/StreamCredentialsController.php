<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands an authenticated API client everything it needs to subscribe to ONE
 * project's site-review event stream: the public hub URL, the per-project
 * topic, and a subscriber-scoped Mercure JWT. The bridge CLI calls this with
 * its API token and a ?site= handle (project id or name), then opens an SSE
 * connection to {hubUrl}?topic={topic} with the returned JWT.
 *
 * Account-level tokens only. Project-bound widget tokens (embedded in page
 * HTML, public by design) are rejected with 403 — their contract is "one
 * project, drafts + submit, nothing else"; letting one mint subscriber JWTs
 * would let any page visitor spy on the owner's review streams.
 *
 * Role gating (ROLE_API_SITE_REVIEW) comes from the firewall access_control on
 * ^/api/site-review; project ownership is enforced here via the owner-scoped
 * repository lookup — the caller can only ever obtain creds for its own
 * projects.
 */
#[Route(
    '/api/site-review/stream',
    name: 'api_site_review_stream',
    methods: ['GET'],
)]
final class StreamCredentialsController extends AppController
{
    public function __construct(
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly ProjectRepository $projects,
        private readonly SiteReviewTopicBuilder $topicBuilder,

        #[Autowire(service: 'mercure.hub.default.jwt.factory')]
        private readonly TokenFactoryInterface $tokenFactory,

        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private readonly string $hubUrl,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (null !== $this->projectResolver->resolveWidgetProject()) {
            return $this->json(['error' => 'site_bound_token_not_allowed'], JsonResponse::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Stream endpoint reached without an authenticated User.');
        }

        $handle = trim($request->query->getString('site'));
        if ('' === $handle) {
            return $this->json(['error' => 'missing_site_parameter'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $project = $this->projects->findOneByIdOrNameForOwner($handle, $user);
        if (null === $project) {
            return $this->json(['error' => 'site_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $topic = $this->topicBuilder->forProject($project->id ?? throw new \LogicException('Project has no id.'));
        $jwt = $this->tokenFactory->create([$topic], []);

        return $this->json([
            'hubUrl' => $this->hubUrl,
            'topic' => $topic,
            'jwt' => $jwt,
            'site' => ['id' => (string) $project->id, 'name' => $project->name],
        ]);
    }
}
