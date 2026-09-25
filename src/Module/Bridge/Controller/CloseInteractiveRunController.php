<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\CloseInteractiveRunCommand;
use App\Module\Bridge\Command\CloseInteractiveRunHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** The form targets the card's runs frame, so the redirect lands on that frame alone. */
#[CsrfToken('worker-run-close')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/{runId}/close',
    name: 'app_project_worker_run_close',
    requirements: ['runId' => Requirement::UUID],
    methods: ['POST'],
)]
class CloseInteractiveRunController extends AppController
{
    public function __construct(
        private readonly CloseInteractiveRunHandler $closeInteractiveRun,
    ) {
    }

    public function __invoke(Project $project, string $runId): Response
    {
        $run = ($this->closeInteractiveRun)(new CloseInteractiveRunCommand($project, Uuid::fromString($runId)))
            ?? throw $this->createNotFoundException();

        return $this->redirectToRoute('app_project_card_worker_runs', [
            'id' => (string) $project->id,
            'cardId' => (string) $run->cardId,
        ], Response::HTTP_SEE_OTHER);
    }
}
