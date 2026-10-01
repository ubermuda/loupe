<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\SetBridgePauseCommand;
use App\Module\Bridge\Command\SetBridgePauseHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('bridge-pause')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/agents/{bridgeId}/pause',
    name: 'app_project_bridge_pause',
    requirements: ['bridgeId' => Requirement::UUID],
    methods: ['POST'],
)]
final class PauseBridgeController extends AppController
{
    public function __construct(
        private readonly SetBridgePauseHandler $setBridgePause,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneFollowingProject(project, bridgeId)')] Bridge $bridge,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The project voter admits only a signed-in user.');
        }

        try {
            ($this->setBridgePause)(new SetBridgePauseCommand($project->owner, $bridge->id, true, $user));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('bridge-pause', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_project_agents', [
            'id' => (string) $project->id,
            '_fragment' => 'agent-connection-'.$bridge->id,
        ], Response::HTTP_SEE_OTHER);
    }
}
