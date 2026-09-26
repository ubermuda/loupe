<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportSessionUsageCommand;
use App\Module\Bridge\Command\ReportSessionUsageHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records the usage of every worker process of one claude session against one
 * of the caller's projects. The firewall admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/projects/{handle}/worker-runs/sessions/{sessionId}/usage',
    name: 'api_project_worker_run_session_usage_report',
    requirements: ['handle' => '[^/]+', 'sessionId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportSessionUsageController extends AppController
{
    public function __construct(
        private readonly ReportSessionUsageHandler $reportSessionUsage,
    ) {
    }

    public function __invoke(string $handle, string $sessionId, #[MapRequestPayload] ReportSessionUsageRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Session usage endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($sessionId)) {
            return $this->json(['error' => 'invalid_session_id'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ($this->reportSessionUsage)(new ReportSessionUsageCommand(
            owner: $user,
            handle: $handle,
            sessionId: Uuid::fromString($sessionId),
            processes: $payload->processes(),
        ));

        if (null === $result->runs) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        if ($result->runs !== $result->processes) {
            return $this->json(['error' => 'process_count_mismatch'], JsonResponse::HTTP_CONFLICT);
        }

        return $this->json(['runs' => $result->runs, 'updated' => $result->updated]);
    }
}
