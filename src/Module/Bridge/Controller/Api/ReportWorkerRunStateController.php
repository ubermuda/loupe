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
 * Every refusal the controller makes carries an error code, so the bridge can
 * tell an unknown project from an instance with agent push switched off.
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
            subject: $payload->subject(),
            cardNumber: $payload->cardNumber,
            workRequestId: $payload->workRequestId(),
            workKind: $payload->workKind,
            ruleId: $payload->ruleId,
            sessionId: $payload->sessionId(),
            startedAt: $payload->startedAt(),
            endedAt: $payload->endedAt(),
            exitCode: $payload->exitCode,
            hasResult: $payload->hasResult(),
            failureReason: $payload->failureReason(),
            output: $payload->output,
            resultStatus: $payload->resultStatus,
            resultReason: $payload->resultReason(),
            resultFields: $payload->resultFields(),
            continues: $payload->continues(),
            resumeSkipped: $payload->resumeSkipped,
            usage: $payload->usage?->report(),
            workerPool: $payload->workerPool,
            experiment: $payload->experiment,
            variant: $payload->variant,
            requestedModel: $payload->requestedModel,
            switchedFrom: $payload->switchedFrom,
            kind: $payload->kind(),
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
