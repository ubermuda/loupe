<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\ReviseDocumentFormType;
use App\Module\Review\Form\ReviseDocumentRequest;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/revise',
    name: 'app_document_revise',
    methods: ['POST'],
)]
final class ReviseDocumentController extends AppController
{
    public function __construct(
        private readonly ReviseDocumentHandler $reviseDocument,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $parameters = ['projectId' => (string) $document->project->id, 'documentId' => (string) $document->id];
        $data = new ReviseDocumentRequest();
        $form = $this->createForm(ReviseDocumentFormType::class, $data, [
            'action' => $this->generateUrl('app_document_revise', $parameters),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $data->versionNumber) {
            try {
                ($this->reviseDocument)(new ReviseDocumentCommand(
                    document: $document,
                    markdown: $data->markdown ?? '',
                    description: $data->description ?? '',
                    title: $data->title,
                    versionNumber: $data->versionNumber,
                ));
                $this->addFlash('success', $this->translator->trans('review.revise.flash.success'));

                return $this->redirectToRoute('app_document_review', $parameters);
            } catch (DomainErrors $errors) {
                $this->applyDomainErrors($form, $errors);
            }
        }

        return $this->forward(ShowDocumentController::class, [
            ...$parameters,
            'reviseDocumentForm' => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
