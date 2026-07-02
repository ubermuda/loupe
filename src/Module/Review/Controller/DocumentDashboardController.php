<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/documents',
    name: 'app_documents',
    methods: ['GET'],
)]
class DocumentDashboardController extends AppController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly ProjectRepository $projects,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        // Transitional shim: list documents across all of the user's projects.
        // The Loop redesign (Task 4) replaces this with a project-scoped route.
        $documents = array_merge(
            ...array_map(
                $this->documents->findByProject(...),
                $this->projects->findByOwner($user),
            ),
        );

        return $this->render('review/dashboard.html.twig', [
            'documents' => $documents,
        ]);
    }
}
