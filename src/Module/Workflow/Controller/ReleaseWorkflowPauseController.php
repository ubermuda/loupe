<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardDirectory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('workflow-pause-release')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/workflow-pauses/{pauseId}/release',
    name: 'app_workflow_card_pause_release',
    requirements: ['projectId' => Requirement::UUID, 'cardId' => Requirement::UUID, 'pauseId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ReleaseWorkflowPauseController extends AppController
{
    public function __construct(
        private readonly ReleaseWorkflowPauseHandler $release,
        private readonly CardDirectory $cards,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        string $pauseId,
        string $cardId,
        #[MapEntity(id: 'projectId')] Project $project,
    ): Response {
        $card = $this->cards->findInProject($project->id ?? throw new \LogicException('A stored project has an id.'), Uuid::fromString($cardId))
            ?? throw $this->createNotFoundException();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The project voter admits only a signed-in user.');
        }

        try {
            ($this->release)(new ReleaseWorkflowPauseCommand(
                $card,
                $user->id ?? throw new \LogicException('A signed-in user has an id.'),
                Actor::Human,
                Uuid::fromString($pauseId),
            ));
            $this->addFlash('success', $this->translator->trans('workflow.pause_release.flash.released'));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $refusal) {
                $this->addFlash('error', $this->translator->trans($refusal));
            }
        }

        return $this->redirectToRoute('app_board_card', [
            'projectId' => (string) $project->id,
            'cardId' => (string) $card->id,
        ], Response::HTTP_SEE_OTHER);
    }
}
