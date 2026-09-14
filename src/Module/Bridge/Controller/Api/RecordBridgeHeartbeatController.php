<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records that one of the caller's bridges is alive. The firewall admits
 * agent-scoped tokens alone.
 */
// 404 rather than a disabled-looking 403, matching the events endpoint. A
// bridge cannot run while push is off, so there is no bridge to hear from.
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
    ) {
    }

    public function __invoke(string $bridgeId, #[MapRequestPayload] RecordBridgeHeartbeatRequest $payload): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Bridge heartbeat endpoint reached without an authenticated User.');
        }

        ($this->recordHeartbeat)(new RecordBridgeHeartbeatCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            projects: $payload->projectIds(),
            cliVersion: $payload->cliVersion(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
