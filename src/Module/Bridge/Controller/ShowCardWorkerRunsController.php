<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The runs section of a card page, alone, for the frame a live update reloads.
 * The card is a raw id, because no module may import Board.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/card/{cardId}',
    name: 'app_project_card_worker_runs',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['GET'],
)]
class ShowCardWorkerRunsController extends AppController
{
    public function __invoke(Project $project, string $cardId): Response
    {
        return $this->render('@Bridge/_card_worker_runs.html.twig', [
            'project' => $project,
            'cardId' => $cardId,
        ]);
    }
}
