<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SelectDecisionOptionCommand;
use App\Module\Review\Command\SelectDecisionOptionHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\SelectDecisionOptionFormType;
use App\Module\Review\Form\SelectDecisionOptionRequest;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/decisions',
    name: 'app_document_decision_select',
    methods: ['POST'],
)]
final class SelectDecisionOptionController extends AppController
{
    public function __construct(
        private readonly SelectDecisionOptionHandler $selectDecisionOption,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        Request $request,
    ): Response {
        $data = new SelectDecisionOptionRequest();
        $form = $this->createForm(SelectDecisionOptionFormType::class, $data);
        $form->handleRequest($request);

        $message = null;
        $failed = true;

        if (!$form->isSubmitted() || !$form->isValid()) {
            $message = $this->translator->trans('review.decision.error.save_failed');
        } else {
            try {
                ($this->selectDecisionOption)(new SelectDecisionOptionCommand(
                    document: $document,
                    decisionId: $data->decisionId ?? throw new \LogicException('decisionId required after validation'),
                    optionIndex: $data->optionIndex ?? throw new \LogicException('optionIndex required after validation'),
                    displayedVersionNumber: $data->versionNumber ?? throw new \LogicException('versionNumber required after validation'),
                ));
                $message = $this->translator->trans('review.decision.status.saved');
                $failed = false;
            } catch (DomainErrors $e) {
                // Deliberately not mapped onto the form fields. The form is
                // hidden and renders no field a reviewer can see, so a
                // form_errors() output would be invisible; the status line is
                // the only surface. The field names in $e->errors are dropped
                // and only the messages survive.
                $message = implode(' ', array_map(
                    fn (string $key): string => $this->translator->trans($key),
                    $e->errors,
                ));
            }
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            // Without the flash a failed answer is a silent no-op here: the
            // status line the stream targets is only refreshed by the stream.
            if ($failed) {
                $this->addFlash('error', $message);
            }

            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        // Only the status line is streamed back. The radio the reviewer clicked
        // is already in the state they left it, and replacing the prose would
        // tear out the comment highlights anchored into it.
        return new Response(
            $this->renderView('@Review/_decision_status.stream.html.twig', [
                'message' => $message,
                'failed' => $failed,
            ]),
            $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
