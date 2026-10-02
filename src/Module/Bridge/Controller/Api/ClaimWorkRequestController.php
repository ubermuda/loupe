<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ClaimWorkRequestCommand;
use App\Module\Bridge\Command\ClaimWorkRequestHandler;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Outbox\AgentPush;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Claims a work request for one of the caller's bridges. The firewall admits
 * agent-scoped tokens alone. The answer carries the claim token, and the
 * bridge sends it back with the result.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[RateLimit('agent_work_request_claims', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/bridges/{bridgeId}/work-requests/{workRequestId}/claim',
    name: 'api_bridge_work_request_claim',
    requirements: ['bridgeId' => Requirement::UUID, 'workRequestId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ClaimWorkRequestController extends AppController
{
    public function __construct(
        private readonly ClaimWorkRequestHandler $claimWorkRequest,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(string $bridgeId, string $workRequestId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Work request claim endpoint reached without an authenticated User.');
        }

        $result = ($this->claimWorkRequest)(new ClaimWorkRequestCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            workRequestId: Uuid::fromString($workRequestId),
        ));

        $request = $result->request;
        if (null === $request) {
            $refusal = $result->refusal ?? throw new \LogicException('A claim with no request carries a refusal.');

            return $this->json(['error' => $refusal->code()], $refusal->httpStatus());
        }

        return $this->json([
            'workRequestId' => (string) $request->id,
            'claimToken' => (string) $request->claimToken,
            'leaseUntil' => $request->leaseUntil?->format(\DateTimeInterface::ATOM),
            'workRequest' => WorkRequestPayload::of($request),
        ]);
    }
}
