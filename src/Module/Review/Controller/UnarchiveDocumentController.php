<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\UnarchiveDocumentCommand;
use App\Module\Review\Command\UnarchiveDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\View\DocumentListQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('document-archive')]
#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/unarchive',
    name: 'app_document_unarchive',
    methods: ['POST'],
)]
final class UnarchiveDocumentController extends AppController
{
    public function __construct(
        private readonly UnarchiveDocumentHandler $unarchiveDocument,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        ($this->unarchiveDocument)(new UnarchiveDocumentCommand($document));

        $this->addFlash('success', $this->translator->trans('review.archive.flash.unarchived', ['%title%' => $document->title]));

        return $this->redirectToRoute('app_project_documents', [
            'id' => (string) $document->project->id,
            ...DocumentListQuery::fromQuery($request->query)->routeParams(),
        ]);
    }
}
