<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ResolveExperimentPinCommand;
use App\Module\Bridge\Command\ResolveExperimentPinHandler;
use App\Outbox\AgentPush;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Answers the variant of one experiment that one card runs with. The firewall
 * admits agent-scoped tokens alone.
 *
 * The bridge reads a 404 with no error code as a server with no such endpoint,
 * so every refusal the controller makes carries a code. The route accepts any
 * segment for that reason, and the controller checks the names itself.
 */
#[RequireFeatureFlag(AgentPush::FLAG)]
#[Route(
    '/api/projects/{handle}/experiments/{experiment}/pins/{cardId}',
    name: 'api_project_experiment_pin_resolve',
    requirements: ['handle' => '[^/]+', 'experiment' => '[^/]+', 'cardId' => '[^/]+'],
    methods: ['PUT'],
)]
final class ResolveExperimentPinController extends AppController
{
    public function __construct(
        private readonly ResolveExperimentPinHandler $resolveExperimentPin,
    ) {
    }

    /** An empty body gives a null payload, which the controller refuses with its own code. */
    public function __invoke(
        string $handle,
        string $experiment,
        string $cardId,
        #[MapRequestPayload] ?ResolveExperimentPinRequest $payload = null,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Experiment pin endpoint reached without an authenticated User.');
        }

        if (!Uuid::isValid($cardId)) {
            return $this->json(['error' => 'invalid_card_id'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!ResolveExperimentPinRequest::isName($experiment)) {
            return $this->json(['error' => 'invalid_experiment'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $choice = $payload?->choice();
        if (null === $choice) {
            return $this->json(['error' => 'invalid_variants'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ($this->resolveExperimentPin)(new ResolveExperimentPinCommand(
            owner: $user,
            handle: $handle,
            cardId: Uuid::fromString($cardId),
            experiment: $experiment,
            candidate: $choice['candidate'],
            variants: $choice['variants'],
            weights: $payload?->weights(),
            metrics: $payload?->metrics(),
        ));

        if (null === $result->variant) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['variant' => $result->variant, 'switchedFrom' => $result->switchedFrom]);
    }
}
