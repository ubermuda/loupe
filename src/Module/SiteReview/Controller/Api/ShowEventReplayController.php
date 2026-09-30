<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\ShowEventReplayCommand;
use App\Module\SiteReview\Command\ShowEventReplayHandler;
use App\Outbox\AgentPush;
use App\Outbox\Entity\OutboxEvent;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Hands the bridge CLI the outbox events of the caller's projects after its
 * cursor, in the shape the hub delivers them, so it can catch up on what it
 * missed while it was stopped or reconnecting. The firewall admits
 * agent-scoped tokens alone.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[RateLimit('agent_event_replay', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/events/replay',
    name: 'api_event_replay',
    methods: ['GET'],
)]
final class ShowEventReplayController extends AppController
{
    public function __construct(
        private readonly ShowEventReplayHandler $showEventReplay,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(
        #[MapQueryParameter(options: ['min_range' => 0], validationFailedStatusCode: 400)]
        int $after,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Event replay endpoint reached without an authenticated User.');
        }

        $view = ($this->showEventReplay)(new ShowEventReplayCommand($user, $after));

        return new JsonResponse([
            'events' => array_map(
                static fn (OutboxEvent $event): array => [
                    'id' => $event->sequence ?? throw new \LogicException('Outbox event has no sequence.'),
                    'type' => $event->type,
                    'data' => $event->payload,
                ],
                $view->events,
            ),
            'hasMore' => $view->hasMore,
        ]);
    }
}
