<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\ReportBridgeRulesCommand;
use App\Module\Board\Command\ReportBridgeRulesHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Replaces the rule health one bridge reports for one of the caller's
 * projects. The firewall admits agent-scoped tokens alone, and
 * RefuseBridgeRuleReportsWhileBoardDisabled answers before this runs while
 * the board is off.
 */
#[Route(
    '/api/projects/{handle}/bridges/{bridgeId}/rules',
    name: 'api_project_bridge_rules_report',
    requirements: ['handle' => '[^/]+', 'bridgeId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class ReportBridgeRulesController extends AppController
{
    public function __construct(
        private readonly ReportBridgeRulesHandler $reportBridgeRules,
    ) {
    }

    public function __invoke(
        string $handle,
        string $bridgeId,
        #[MapRequestPayload] ReportBridgeRulesRequest $payload,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Bridge rules endpoint reached without an authenticated User.');
        }

        $report = ($this->reportBridgeRules)(new ReportBridgeRulesCommand(
            owner: $user,
            handle: $handle,
            bridgeId: Uuid::fromString($bridgeId),
            rules: array_map(static fn (BridgeRuleInput $rule): array => $rule->toArray(), array_values($payload->rules ?? [])),
        ));
        if (null === $report) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
