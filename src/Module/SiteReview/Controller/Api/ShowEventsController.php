<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\ShowEventsCommand;
use App\Module\SiteReview\Command\ShowEventsHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Hands the bridge CLI what it needs to follow the events of every project its
 * user owns: the public hub URL, the user's own topic, a subscriber JWT for that
 * one topic, and the projects whose events arrive on it.
 *
 * Agent-scoped tokens only. The firewall grants this path to ROLE_API_AGENT
 * alone, so a project-bound widget token gets 403 `insufficient_scope` before
 * this class runs. A widget token is embedded in public page HTML, and letting
 * one mint subscriber JWTs would let any page visitor watch the owner's streams.
 */
// 404 rather than a disabled-looking 403: with push off there is no hub to
// subscribe to, so there is nothing here to be authorized for.
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/events',
    name: 'api_events',
    methods: ['GET'],
)]
final class ShowEventsController extends AppController
{
    public function __construct(
        private readonly ShowEventsHandler $showEvents,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Events endpoint reached without an authenticated User.');
        }

        $view = ($this->showEvents)(new ShowEventsCommand($user));

        return $this->json([
            'hubUrl' => $view->hubUrl,
            'jwt' => $view->jwt,
            'topic' => $view->topic,
            'projects' => array_map(
                static fn (Project $project): array => [
                    'id' => (string) $project->id,
                    'slug' => $project->slug,
                    'name' => $project->name,
                ],
                $view->projects,
            ),
            // An empty map must encode as {}, and the serializer writes [] for an
            // empty object unless it is told to preserve it.
            'flags' => (object) $view->flags,
        ], context: [AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true]);
    }
}
