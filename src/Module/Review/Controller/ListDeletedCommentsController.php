<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\ListDeletedCommentsCommand;
use App\Module\Review\Command\ListDeletedCommentsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/deleted-threads',
    name: 'app_document_deleted_threads',
    methods: ['GET'],
)]
final class ListDeletedCommentsController extends AppController
{
    public function __construct(
        private readonly ListDeletedCommentsHandler $listDeletedComments,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $view = ($this->listDeletedComments)(new ListDeletedCommentsCommand($document));

        return $this->render('@Review/list_deleted_comments.html.twig', [
            'document' => $view->document,
            'comments' => $view->comments,
            'recoveryForm' => $this->getInjectedFormView($request, 'recoveryForm'),
            'failedCommentId' => $request->attributes->get('failedCommentId'),
            'failedAction' => $request->attributes->get('failedAction'),
        ]);
    }
}
