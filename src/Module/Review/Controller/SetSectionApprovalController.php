<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetSectionApprovalCommand;
use App\Module\Review\Command\SetSectionApprovalHandler;
use App\Module\Review\Command\ShowSectionSummaryCommand;
use App\Module\Review\Command\ShowSectionSummaryHandler;
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
use Symfony\UX\Turbo\TurboBundle;
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
        private readonly ShowSectionSummaryHandler $showSectionSummary,
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
        // invisible. Every failure is reported as a message instead.
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

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            if (null === $errorKey) {
                $this->addFlash('success', $this->translator->trans(
                    SetSectionApprovalRequest::ACTION_APPROVE === $data->action
                        ? 'review.section.flash.approved'
                        : 'review.section.flash.withdrawn',
                ));
            } else {
                $this->addFlash('error', $this->translator->trans($errorKey));
            }

            // No `_fragment` naming the section: Turbo treats a redirect to the URL
            // the page is already on, fragment included, as an anchor scroll and
            // renders nothing, so a second press on one section did nothing at all.
            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        // Read back after the write, so every copy of the list reports what is
        // stored rather than what was asked for. Against the version the page
        // was rendered from, which is what the reviewer still sees when a stale
        // press is refused.
        $summary = ($this->showSectionSummary)(
            new ShowSectionSummaryCommand($document, $user, $data->versionNumber),
        );

        // Only a saved press restates its control. A refusal changed nothing in
        // the browser, because the press is a plain submit rather than a
        // client-side toggle, so there is nothing to put back.
        $row = null === $errorKey
            ? array_find($summary->rows, static fn (array $row): bool => $row['headingId'] === $data->headingId)
            : null;

        return new Response(
            $this->renderView('@Review/_section_approval.stream.html.twig', [
                'message' => null === $errorKey ? null : $this->translator->trans($errorKey),
                'rows' => $summary->rows,
                'approvedCount' => $summary->approvedCount,
                'controlId' => null === $row ? null : 'section-approve-'.$row['headingId'],
                'controlHtml' => null === $row ? null : trim($this->renderView('@Review/_section_approval_control.html.twig', [
                    'document' => $document,
                    'headingId' => $row['headingId'],
                    'label' => $row['label'],
                    'approved' => $row['approved'],
                    'versionNumber' => $data->versionNumber,
                ])),
            ]),
            null === $errorKey ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
