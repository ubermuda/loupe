<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\UndoVerdictFormType;
use App\Module\Review\Form\UndoVerdictRequest;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/undo',
    name: 'app_document_review_undo',
    methods: ['POST'],
)]
final class UndoVerdictController extends AppController
{
    public function __construct(
        private readonly UndoVerdictHandler $undoVerdict,
        private readonly TranslatorInterface $translator,
        private readonly Auditor $auditor,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $routeParameters = [
            'projectId' => (string) $project->id,
            'documentId' => (string) $document->id,
        ];
        $data = new UndoVerdictRequest();
        $form = $this->createForm(UndoVerdictFormType::class, $data, [
            'action' => $this->generateUrl('app_document_review_undo', $routeParameters),
        ]);
        $form->handleRequest($request);

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        if ($form->isSubmitted() && $form->isValid() && null !== $data->reviewId) {
            try {
                ($this->undoVerdict)(new UndoVerdictCommand(document: $document, actor: $user, reviewId: $data->reviewId));
                $this->addFlash('success', $this->translator->trans('review.document.flash.verdict_undone'));
                $this->auditor->record(
                    'review.document_verdict_undone',
                    AuditOutcome::Success,
                    [
                        'documentId' => (string) $document->id,
                        'status' => $document->status->value,
                    ],
                    new AuditSubject('document', (string) $document->id),
                );

                return $this->redirectToRoute('app_document_review', $routeParameters);
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->forward(ShowDocumentController::class, [
            ...$routeParameters,
            'undoVerdictForm' => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
