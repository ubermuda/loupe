<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Repository\AmbiguousProjectHandleException;
use App\Module\SiteReview\Command\ShowStreamCredentialsCommand;
use App\Module\SiteReview\Command\ShowStreamCredentialsHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Hands an authenticated API client everything it needs to subscribe to ONE
 * project's site-review event stream: the public hub URL, the per-project
 * topic, and a subscriber-scoped Mercure JWT. The bridge CLI calls this with
 * its API token and a handle in the path (project id, name or slug), then opens
 * an SSE connection to {hubUrl}?topic={topic} with the returned JWT.
 *
 * Agent-scoped tokens only. The firewall grants this path to ROLE_API_AGENT
 * alone, so a project-bound widget token gets 403 `insufficient_scope` before
 * this class runs. A widget token is embedded in public page HTML, and letting
 * one mint subscriber JWTs would let any page visitor watch the owner's review
 * streams. Project ownership is enforced by the handler's owner-scoped lookup.
 */
// 404 rather than a disabled-looking 403: with push off there is no hub to
// subscribe to, so there is nothing here to be authorized for. The bridge CLI
// treats it as "this instance does not do push".
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/projects/{handle}/stream',
    name: 'api_project_stream',
    // A project name may hold a slash, which the default requirement refuses.
    requirements: ['handle' => '.+'],
    methods: ['GET'],
)]
final class StreamCredentialsController extends AppController
{
    public function __construct(
        private readonly ShowStreamCredentialsHandler $showStreamCredentials,
    ) {
    }

    public function __invoke(string $handle): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Stream endpoint reached without an authenticated User.');
        }

        try {
            $view = ($this->showStreamCredentials)(new ShowStreamCredentialsCommand($user, trim($handle)));
        } catch (AmbiguousProjectHandleException $e) {
            return $this->json(['error' => 'ambiguous_site', 'message' => $e->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
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
