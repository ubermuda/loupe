<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\DiffDocumentVersionsCommand;
use App\Module\Review\Command\DiffDocumentVersionsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\ValueObject\DiffView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Re-reviewing a revised document otherwise means reading all of it again. This
// shows the delta between two versions instead: the review page with the
// document pane holding the diff, so the version list, the references and the
// reading measure are the ones the reviewer already knows.
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/diff/{fromVersionNumber}/{toVersionNumber}',
    name: 'app_document_review_diff',
    requirements: ['fromVersionNumber' => '\d+', 'toVersionNumber' => '\d+'],
    methods: ['GET'],
)]
final class DiffDocumentVersionsController extends AppController
{
    public function __construct(
        private readonly DiffDocumentVersionsHandler $diffDocumentVersions,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        int $fromVersionNumber,
        int $toVersionNumber,
    ): Response {
        if ($fromVersionNumber >= $toVersionNumber) {
            throw $this->createNotFoundException('A diff runs from an earlier version to a later one.');
        }

        $view = ($this->diffDocumentVersions)(new DiffDocumentVersionsCommand(
            document: $document,
            fromVersionNumber: $fromVersionNumber,
            toVersionNumber: $toVersionNumber,
            view: DiffView::fromRequestValue($request->query->all()['view'] ?? null),
        ));

        return $this->render('@Review/diff_document_versions.html.twig', [
            'document' => $document,
            'version' => $view->version,
            'versions' => $view->versions,
            'diffMode' => true,
            'diffView' => $view->view,
            'diff' => $view->diff,
            'renderedDiff' => $view->renderedDiff,
            'diffChangeCount' => $view->changeCount,
            'diffRefusal' => $view->diffRefusal,
            'diffFromVersion' => $fromVersionNumber,
            // A diff has no text basis to anchor a quote against and describes no
            // single version, so nothing that writes to one is offered here.
            'readOnly' => true,
            'comments' => $view->comments,
            'orphanedCount' => $view->orphanedCount,
            'signals' => $view->signals,
        ]);
    }
}
