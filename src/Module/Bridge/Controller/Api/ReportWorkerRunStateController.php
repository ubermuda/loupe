<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records one state of one worker run against one of the caller's projects.
 * The firewall admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/projects/{handle}/worker-runs/{runId}',
    name: 'api_project_worker_run_state_report',
    requirements: ['handle' => '[^/]+', 'runId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportWorkerRunStateController extends AppController
{
    public function __construct(
        private readonly ReportWorkerRunStateHandler $reportWorkerRunState,
    ) {
    }

    public function __invoke(string $handle, string $runId, #[MapRequestPayload] ReportWorkerRunStateRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Worker run state endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($runId)) {
            return $this->json(['error' => 'invalid_run_id'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ($this->reportWorkerRunState)(new ReportWorkerRunStateCommand(
            owner: $user,
            handle: $handle,
            runKey: Uuid::fromString($runId),
            bridgeId: $payload->bridgeId(),
            state: $payload->state(),
            at: $payload->at(),
            cardId: $payload->cardId(),
            cardNumber: $payload->cardNumber ?? throw new \LogicException('cardNumber is required after validation.'),
            ruleName: $payload->ruleName(),
            sessionId: $payload->sessionId(),
            startedAt: $payload->startedAt(),
            endedAt: $payload->endedAt(),
            exitCode: $payload->exitCode,
            failureReason: $payload->failureReason(),
            output: $payload->output,
        ));

        if (null === $result->run) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(
            ['id' => (string) $result->run->id],
            $result->newState ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
        );
    }
}
