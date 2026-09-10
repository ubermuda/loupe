<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\ShowDocumentHistoryCommand;
use App\Module\Review\Command\ShowDocumentHistoryHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The whole revision history on a page of its own. The review page's Versions
// panel lists the current version alone, because a list of every version with
// every note pushed the document's first paragraph below the fold.
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
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $view = ($this->showDocumentHistory)(new ShowDocumentHistoryCommand($document));

        return $this->render('@Review/show_document_history.html.twig', [
            'document' => $view->document,
            'versions' => $view->versions,
        ]);
    }
}
