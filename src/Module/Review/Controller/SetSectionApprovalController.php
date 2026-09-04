<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetSectionApprovalCommand;
use App\Module\Review\Command\SetSectionApprovalHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\SetSectionApprovalFormType;
use App\Module\Review\Form\SetSectionApprovalRequest;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('section-approval')]
#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/sections',
    name: 'app_document_section_approval',
    methods: ['POST'],
)]
final class SetSectionApprovalController extends AppController
{
    public function __construct(
        private readonly SetSectionApprovalHandler $setSectionApproval,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $data = new SetSectionApprovalRequest();
        $form = $this->createForm(SetSectionApprovalFormType::class, $data);
        $form->handleRequest($request);

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        // The panel renders no field a reviewer can see, so a form error would be
        // invisible. Every failure is reported as a flash on the page it returns to.
        $errorKey = null;
        if (!$form->isSubmitted() || !$form->isValid()) {
            $errorKey = 'review.section.error.save_failed';
        } else {
            try {
                ($this->setSectionApproval)(new SetSectionApprovalCommand(
                    document: $document,
                    reviewer: $user,
                    headingId: $data->headingId ?? throw new \LogicException('headingId required after validation'),
                    approved: SetSectionApprovalRequest::ACTION_APPROVE === $data->action,
                    displayedVersionNumber: $data->versionNumber ?? throw new \LogicException('versionNumber required after validation'),
                ));
            } catch (DomainErrors $e) {
                $errorKey = array_first($e->errors);
            }
        }

        if (null === $errorKey) {
            $this->addFlash('success', $this->translator->trans(
                SetSectionApprovalRequest::ACTION_APPROVE === $data->action
                    ? 'review.section.flash.approved'
                    : 'review.section.flash.withdrawn',
            ));
        } else {
            $this->addFlash('error', $this->translator->trans($errorKey));
        }

        return $this->redirectToRoute('app_document_review', [
            'projectId' => (string) $project->id,
            'documentId' => (string) $document->id,
            '_fragment' => $data->headingId ?? '',
        ]);
    }
}
