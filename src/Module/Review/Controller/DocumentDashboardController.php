<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/documents',
    name: 'app_project_documents',
    methods: ['GET'],
)]
class DocumentDashboardController extends AppController
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('review/dashboard.html.twig', [
            'project' => $project,
            'documents' => $this->documents->findByProject($project),
        ]);
    }
}
