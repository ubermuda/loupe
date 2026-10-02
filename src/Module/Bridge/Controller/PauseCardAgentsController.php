<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\PauseCardAgentsCommand;
use App\Module\Bridge\Command\PauseCardAgentsHandler;
use App\Module\Bridge\Service\CardColumnLookupInterface;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('card-agents')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/card/{cardId}/pause',
    name: 'app_project_card_agents_pause',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class PauseCardAgentsController extends AppController
{
    public function __construct(
        private readonly PauseCardAgentsHandler $pause,
        private readonly CardColumnLookupInterface $cards,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project, string $cardId): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The project voter admits only a signed-in user.');
        }
        $card = Uuid::fromString($cardId);
        if (null === $this->cards->columnOf($project, $card)) {
            throw $this->createNotFoundException();
        }

        // The card frame reads a session that writes nothing, so a flash there would never clear.
        $fromCardFrame = 'card-worker-runs' === $request->headers->get('Turbo-Frame');
        try {
            ($this->pause)(new PauseCardAgentsCommand($project, $card, $user));
        } catch (DomainErrors $e) {
            if (\in_array(PauseCardAgentsHandler::CARD_GONE, $e->errors, true)) {
                throw $this->createNotFoundException();
            }
            $messages = array_values(array_map($this->translator->trans(...), $e->errors));
            if ($fromCardFrame) {
                return $this->render('@Bridge/_card_worker_runs.html.twig', [
                    'project' => $project,
                    'cardId' => $cardId,
                    'commandErrors' => $messages,
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            foreach ($messages as $message) {
                $this->addFlash('error', $message);
            }
        }

        if ($fromCardFrame) {
            return $this->redirectToRoute('app_project_card_worker_runs', [
                'id' => (string) $project->id,
                'cardId' => $cardId,
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_board_card', [
            'projectId' => (string) $project->id,
            'cardId' => $cardId,
        ], Response::HTTP_SEE_OTHER);
    }
}
