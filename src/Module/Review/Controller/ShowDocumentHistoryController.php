<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\ShowDocumentCommand;
use App\Module\Review\Command\ShowDocumentHandler;
use App\Module\Review\Command\ShowDocumentHistoryCommand;
use App\Module\Review\Command\ShowDocumentHistoryHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\ReviseDocumentFormType;
use App\Module\Review\Form\ReviseDocumentRequest;
use App\Module\Review\Form\SubmitReviewFormType;
use App\Module\Review\Form\SubmitReviewRequest;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/history',
    name: 'app_document_review_history',
    methods: ['GET'],
)]
final class ShowDocumentHistoryController extends AppController
{
    public function __construct(
        private readonly ShowDocumentHistoryHandler $showDocumentHistory,
        private readonly ShowDocumentHandler $showDocument,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $view = ($this->showDocumentHistory)(new ShowDocumentHistoryCommand($document));
        $reader = $this->getUser();
        $current = ($this->showDocument)(new ShowDocumentCommand($document, null, $reader instanceof User ? $reader : null));
        $routeParameters = ['projectId' => (string) $document->project->id, 'documentId' => (string) $document->id];

        return $this->render('@Review/show_document_history.html.twig', [
            'document' => $view->document,
            'versions' => $view->versions,
            'version' => $current->version,
            'sections' => $current->sections,
            'signals' => $current->signals,
            'reviseDocumentForm' => $this->createForm(
                ReviseDocumentFormType::class,
                ReviseDocumentRequest::fromVersion($current->version),
                ['action' => $this->generateUrl('app_document_revise', $routeParameters), 'project' => $document->project, 'document' => $document],
            )->createView(),
            'submitReviewForm' => $this->createForm(
                SubmitReviewFormType::class,
                new SubmitReviewRequest(versionNumber: $current->version->versionNumber, expectedReviewId: $current->latestReviewId),
                ['action' => $this->generateUrl('app_document_review_submit', $routeParameters)],
            )->createView(),
        ]);
    }
}
