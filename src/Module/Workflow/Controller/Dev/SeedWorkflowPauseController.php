<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Dev;

use App\Controller\AppController;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        private readonly PauseCardHandler $pauseCard,

        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): JsonResponse {
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            throw $this->createNotFoundException();
        }

        $pause = ($this->pauseCard)(new PauseCardCommand($card, 'move-refused', 'tech-design-write', CardPauseKind::Retries))
            ?? throw new \LogicException('The card was already paused.');

        return $this->json(['pauseId' => (string) $pause->id], JsonResponse::HTTP_CREATED);
    }
}
