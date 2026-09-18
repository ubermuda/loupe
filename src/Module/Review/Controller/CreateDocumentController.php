<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Form\CreateDocumentFormType;
use App\Module\Review\Form\CreateDocumentRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/documents/create',
    name: 'app_document_create',
    methods: ['POST'],
)]
final class CreateDocumentController extends AppController
{
    public function __construct(
        private readonly CreateDocumentHandler $createDocument,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $data = new CreateDocumentRequest();
        $form = $this->createForm(CreateDocumentFormType::class, $data, [
            'project' => $project,
            'action' => $this->generateUrl('app_document_create', ['id' => (string) $project->id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $document = ($this->createDocument)(new CreateDocumentCommand(
                    project: $project,
                    title: $data->title ?? '',
                    markdown: $data->markdown ?? '',
                    draft: true,
                    workLinkIds: $data->workLinkIds,
                ));
                $this->addFlash('success', $this->translator->trans('review.create.flash.success'));

                return $this->redirectToRoute('app_document_review', [
                    'projectId' => (string) $project->id,
                    'documentId' => (string) $document->id,
                ]);
            } catch (DomainErrors $errors) {
                $this->applyDomainErrors($form, $errors);
            }
        }

        return $this->forward(ListDocumentsController::class, [
            'id' => (string) $project->id,
            'createDocumentForm' => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
