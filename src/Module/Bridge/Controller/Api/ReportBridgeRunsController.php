<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportBridgeRunsCommand;
use App\Module\Bridge\Command\ReportBridgeRunsHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Takes the list of runs one of the caller's bridges still holds, which the
 * bridge sends on each connect. The firewall admits agent-scoped tokens alone.
 */
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/bridges/{bridgeId}/runs',
    name: 'api_bridge_runs_report',
    requirements: ['bridgeId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class ReportBridgeRunsController extends AppController
{
    public function __construct(
        private readonly ReportBridgeRunsHandler $reportBridgeRuns,
    ) {
    }

    public function __invoke(string $bridgeId, #[MapRequestPayload] ReportBridgeRunsRequest $payload): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Bridge runs endpoint reached without an authenticated User.');
        }

        ($this->reportBridgeRuns)(new ReportBridgeRunsCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            runs: $payload->states(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
