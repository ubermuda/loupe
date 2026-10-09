<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Dev;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Workflow\Command\SeedWorkflowPauseCommand;
use App\Module\Workflow\Command\SeedWorkflowPauseHandler;
use App\Module\Workflow\Contract\CardDirectory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/** Dev-only: pauses a card as the engine does after the last refused retry of the tech-design-write rule. */
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/dev/seed/workflow-pause/{projectId}/{cardId}',
    name: 'app_dev_seed_workflow_pause',
    requirements: ['projectId' => Requirement::UUID, 'cardId' => Requirement::UUID],
    methods: ['POST'],
)]
#[When('dev')]
final class SeedWorkflowPauseController extends AppController
{
    public function __construct(
        private readonly SeedWorkflowPauseHandler $seedPause,
        private readonly CardDirectory $cards,

        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(
        string $cardId,
        #[MapEntity(id: 'projectId')] Project $project,
    ): JsonResponse {
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            throw $this->createNotFoundException();
        }

        $card = $this->cards->findInProject($project->id ?? throw new \LogicException('A stored project has an id.'), Uuid::fromString($cardId))
            ?? throw $this->createNotFoundException();
        $pause = ($this->seedPause)(new SeedWorkflowPauseCommand($card));

        return $this->json(['pauseId' => (string) $pause->id], JsonResponse::HTTP_CREATED);
    }
}
