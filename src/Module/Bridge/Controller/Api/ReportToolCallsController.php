<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportToolCallsCommand;
use App\Module\Bridge\Command\ReportToolCallsHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records one batch of the tool calls of one worker run. The run id in the
 * path is the key the bridge gave the run. The firewall admits agent-scoped
 * tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/projects/{handle}/worker-runs/{runId}/tool-calls',
    name: 'api_project_worker_run_tool_calls_report',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportToolCallsController extends AppController
{
    public function __construct(
        private readonly ReportToolCallsHandler $reportToolCalls,
    ) {
    }

    public function __invoke(string $handle, string $runId, #[MapRequestPayload] ReportToolCallsRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Tool call endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($runId)) {
            return $this->json(['error' => 'invalid_run_id'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ($this->reportToolCalls)(new ReportToolCallsCommand(
            owner: $user,
            handle: $handle,
            runKey: Uuid::fromString($runId),
            calls: $payload->calls(),
            timing: $payload->timing(),
        ));

        if (!$result->projectFound) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$result->runFound) {
            return $this->json(['error' => 'run_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['stored' => $result->stored]);
    }
}
