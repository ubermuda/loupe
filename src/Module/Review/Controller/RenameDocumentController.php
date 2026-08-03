<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\RenameDocumentCommand;
use App\Module\Review\Command\RenameDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\RenameDocumentFormType;
use App\Module\Review\Form\RenameDocumentRequest;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\View\DocumentListQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/rename',
    name: 'app_document_rename',
    methods: ['GET', 'POST'],
)]
final class RenameDocumentController extends AppController
{
    public function __construct(
        private readonly RenameDocumentHandler $renameDocument,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        // The list state travels on the URL so the reader lands back on the page
        // and filter they renamed from, whichever way they leave this form.
        $listQuery = DocumentListQuery::fromQuery($request->query);

        $data = RenameDocumentRequest::fromDocument($document);
        $form = $this->createForm(RenameDocumentFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // NotBlank has passed, but "0" is a legal title that `?:` would
            // reject — narrow with an explicit empty check instead.
            $title = trim($data->title ?? '');
            if ('' === $title) {
                throw new \LogicException('title required after validation');
            }

            try {
                ($this->renameDocument)(new RenameDocumentCommand($document, $title));

                $this->addFlash('success', $this->translator->trans('review.rename.flash.success', ['%title%' => $document->title]));

                return $this->redirectToRoute('app_project_documents', [
                    'id' => (string) $document->project->id,
                    ...$listQuery->routeParams(),
                ]);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->renderFormResponse('@Review/rename_document.html.twig', $form, [
            'document' => $document,
            'listQuery' => $listQuery,
        ]);
    }
}
