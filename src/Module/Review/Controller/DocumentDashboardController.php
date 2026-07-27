<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
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
        private readonly DocumentVersionRepository $documentVersions,
        private readonly CommentRepository $comments,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $documents = $this->documents->findByProject($project);
        $latestVersions = $this->documentVersions->findLatestMetaByDocuments($documents);

        $items = array_map(
            function (Document $document) use ($latestVersions): DocumentListItem {
                $meta = $latestVersions[(string) $document->id] ?? throw new \LogicException('Document has no versions.');

                return new DocumentListItem(
                    document: $document,
                    versionNumber: $meta['versionNumber'],
                    updatedAt: $meta['createdAt'],
                    openThreadCount: $this->comments->countOpenByVersion(
                        $this->documentVersions->getReferenceTo($meta['versionId']),
                    ),
                );
            },
            $documents,
        );

        return $this->render('@Review/dashboard.html.twig', [
            'project' => $project,
            'items' => $items,
        ]);
    }
}
