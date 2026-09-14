<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportWorkerRunCommand;
use App\Module\Bridge\Command\ReportWorkerRunHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records one finished worker run against one of the caller's projects. The
 * firewall admits agent-scoped tokens alone, and the row is never updated
 * afterwards.
 */
// 404 rather than a disabled-looking 403, matching the events endpoint. A
// bridge reaches a worker only through the event stream, so an instance with
// push off can produce no run to report.
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/projects/{handle}/worker-runs',
    name: 'api_project_worker_run_report',
    requirements: ['handle' => '[^/]+'],
    methods: ['POST'],
)]
final class ReportWorkerRunController extends AppController
{
    public function __construct(
        private readonly ReportWorkerRunHandler $reportWorkerRun,
    ) {
    }

    public function __invoke(string $handle, #[MapRequestPayload] ReportWorkerRunRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Worker run endpoint reached without an authenticated User.');
        }

        $result = ($this->reportWorkerRun)(new ReportWorkerRunCommand(
            owner: $user,
            handle: $handle,
            bridgeId: $payload->bridgeId(),
            sessionId: $payload->sessionId(),
            cardId: $payload->cardId(),
            cardNumber: $payload->cardNumber ?? throw new \LogicException('cardNumber is required after validation.'),
            ruleName: $payload->ruleName(),
            startedAt: $payload->startedAt(),
            endedAt: $payload->endedAt(),
            exitCode: $payload->exitCode,
            failureReason: $payload->failureReason(),
            output: $payload->output ?? '',
        ));

        if (null === $result->run) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // 200 on a repeat, so a bridge retrying a report whose response it never
        // saw can tell that the row was already there.
        return $this->json(
            ['id' => (string) $result->run->id],
            $result->created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
        );
    }
}
