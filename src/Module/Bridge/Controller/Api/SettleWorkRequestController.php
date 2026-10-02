<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\SettleWorkRequestCommand;
use App\Module\Bridge\Command\SettleWorkRequestHandler;
use App\Outbox\AgentPush;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Records the result of a bridge for a work request it claimed. The firewall
 * admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code.
 */
#[RateLimit('agent_work_request_results', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/bridges/{bridgeId}/work-requests/{workRequestId}/result',
    name: 'api_bridge_work_request_result',
    requirements: ['bridgeId' => Requirement::UUID, 'workRequestId' => Requirement::UUID],
    methods: ['PUT'],
)]
final class SettleWorkRequestController extends AppController
{
    public function __construct(
        private readonly SettleWorkRequestHandler $settleWorkRequest,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    /** An empty body gives a null payload, which the controller refuses with its own code. */
    public function __invoke(
        string $bridgeId,
        string $workRequestId,
        #[MapRequestPayload] ?SettleWorkRequestRequest $payload = null,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Work request result endpoint reached without an authenticated User.');
        }

        $state = $payload?->state();
        if (null === $state) {
            return $this->json(['error' => 'invalid_state'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $claimToken = $payload->claimToken();
        if (null === $claimToken) {
            return $this->json(['error' => 'invalid_claim_token'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$payload->hasValidReason()) {
            return $this->json(['error' => 'invalid_reason'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ($this->settleWorkRequest)(new SettleWorkRequestCommand(
            owner: $user,
            bridgeId: Uuid::fromString($bridgeId),
            workRequestId: Uuid::fromString($workRequestId),
            claimToken: $claimToken,
            state: $state,
            reason: $payload->reason(),
        ));

        $request = $result->request;
        if (null === $request) {
            $refusal = $result->refusal ?? throw new \LogicException('A result with no request carries a refusal.');

            return $this->json(['error' => $refusal->code()], $refusal->httpStatus());
        }

        return $this->json(['workRequestId' => (string) $request->id, 'state' => $request->state->value]);
    }
}
