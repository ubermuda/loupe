<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\ShowStreamCredentialsCommand;
use App\Module\SiteReview\Command\ShowStreamCredentialsHandler;
use App\Module\SiteReview\SiteReviewPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

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
 * ^/api/site-review; project ownership is enforced by the handler's
 * owner-scoped lookup.
 */
// 404 rather than a disabled-looking 403: with push off there is no hub to
// subscribe to, so there is nothing here to be authorized for. The bridge CLI
// treats it as "this instance does not do push".
#[RequireFeatureFlag(SiteReviewPush::FLAG)]
#[Route(
    '/api/site-review/stream',
    name: 'api_site_review_stream',
    methods: ['GET'],
)]
final class StreamCredentialsController extends AppController
{
    public function __construct(
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly ShowStreamCredentialsHandler $showStreamCredentials,
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

        $view = ($this->showStreamCredentials)(new ShowStreamCredentialsCommand($user, $handle));
        if (null === $view->site) {
            return $this->json(['error' => 'site_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'hubUrl' => $view->hubUrl,
            'topic' => $view->topic,
            'jwt' => $view->jwt,
            'site' => ['id' => (string) $view->site->id, 'name' => $view->site->name],
        ]);
    }
}
