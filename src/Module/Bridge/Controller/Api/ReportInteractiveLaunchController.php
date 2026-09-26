<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportInteractiveLaunchCommand;
use App\Module\Bridge\Command\ReportInteractiveLaunchHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records how the bridge's launch of one interactive session on a card went.
 * The firewall admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/projects/{handle}/interactive-runs/{sessionId}',
    name: 'api_project_interactive_run_report',
    requirements: ['handle' => '[^/]+', 'sessionId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ReportInteractiveLaunchController extends AppController
{
    public function __construct(
        private readonly ReportInteractiveLaunchHandler $reportInteractiveLaunch,
    ) {
    }

    public function __invoke(string $handle, string $sessionId, #[MapRequestPayload] ReportInteractiveLaunchRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Interactive launch endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($sessionId)) {
            return $this->json(['error' => 'invalid_session_id'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ($this->reportInteractiveLaunch)(new ReportInteractiveLaunchCommand(
            owner: $user,
            handle: $handle,
            sessionId: Uuid::fromString($sessionId),
            bridgeId: $payload->bridgeId(),
            cardId: $payload->cardId(),
            cardNumber: $payload->cardNumber ?? throw new \LogicException('cardNumber is required after validation.'),
            ruleName: $payload->ruleName(),
            state: $payload->state(),
            at: $payload->at(),
            failureReason: $payload->failureReason(),
        ));

        if (null === $result->run) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(
            ['id' => (string) $result->run->id],
            $result->created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
        );
    }
}
