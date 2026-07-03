<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\View\DocumentListItem;
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
        private readonly CommentRepository $comments,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $items = array_map(
            function ($document): DocumentListItem {
                $version = $document->currentVersion();

                return new DocumentListItem(
                    document: $document,
                    versionNumber: $version->versionNumber,
                    updatedAt: $version->createdAt,
                    openThreadCount: $this->comments->countOpenByVersion($version),
                );
            },
            $this->documents->findByProject($project),
        );

        return $this->render('review/dashboard.html.twig', [
            'project' => $project,
            'items' => $items,
        ]);
    }
}
