<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Outbox\AgentPush;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records that one of the caller's bridges is alive. The firewall admits
 * agent-scoped tokens alone. With push off the answer is a 404, as the events
 * endpoint gives, because a bridge cannot run without push.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[RateLimit('agent_bridge_heartbeats', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/bridges/{bridgeId}/heartbeat',
    name: 'api_bridge_heartbeat',
    requirements: ['bridgeId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class RecordBridgeHeartbeatController extends AppController
{
    public function __construct(
        private readonly RecordBridgeHeartbeatHandler $recordHeartbeat,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(string $bridgeId, #[MapRequestPayload] RecordBridgeHeartbeatRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Bridge heartbeat endpoint reached without an authenticated User.');
        }

        $result = ($this->recordHeartbeat)(new RecordBridgeHeartbeatCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            projects: $payload->projectIds(),
            cliVersion: $payload->cliVersion(),
            updateState: $payload->update?->state(),
            updateVersion: $payload->update?->version,
            hooks: $payload->hooks(),
        ));

        return new JsonResponse(['cliRange' => $result->cliRange]);
    }
}
