<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\ShowStreamCredentialsCommand;
use App\Module\SiteReview\Command\ShowStreamCredentialsHandler;
use App\Module\SiteReview\Command\StreamProjectView;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Hands the bridge CLI what it needs to follow the event stream of every
 * project its user owns: the public hub URL, each project's topic, and one
 * subscriber JWT that covers all of those topics.
 *
 * Agent-scoped tokens only. The firewall grants this path to ROLE_API_AGENT
 * alone, so a project-bound widget token gets 403 `insufficient_scope` before
 * this class runs. A widget token is embedded in public page HTML, and letting
 * one mint subscriber JWTs would let any page visitor watch the owner's streams.
 */
// 404 rather than a disabled-looking 403: with push off there is no hub to
// subscribe to, so there is nothing here to be authorized for.
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route('/api/events', name: 'api_events', methods: ['GET'])]
final class StreamCredentialsController extends AppController
{
    public function __construct(
        private readonly ShowStreamCredentialsHandler $showStreamCredentials,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Events endpoint reached without an authenticated User.');
        }

        $view = ($this->showStreamCredentials)(new ShowStreamCredentialsCommand($user));

        return $this->json([
            'hubUrl' => $view->hubUrl,
            'jwt' => $view->jwt,
            'projects' => array_map(
                static fn (StreamProjectView $project): array => [
                    'id' => (string) $project->project->id,
                    'slug' => $project->project->slug,
                    'name' => $project->project->name,
                    'topic' => $project->topic,
                ],
                $view->projects,
            ),
        ]);
    }
}
