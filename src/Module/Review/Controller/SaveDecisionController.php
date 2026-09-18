<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SaveDecisionCommand;
use App\Module\Review\Command\SaveDecisionHandler;
use App\Module\Review\Command\ShowDecisionSummaryCommand;
use App\Module\Review\Command\ShowDecisionSummaryHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\SaveDecisionFormType;
use App\Module\Review\Form\SaveDecisionRequest;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/decisions/save',
    name: 'app_document_decision_save',
    methods: ['POST'],
)]
final class SaveDecisionController extends AppController
{
    public function __construct(
        private readonly SaveDecisionHandler $saveDecision,
        private readonly ShowDecisionSummaryHandler $showDecisionSummary,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        Request $request,
    ): Response {
        $data = new SaveDecisionRequest();
        $form = $this->createForm(SaveDecisionFormType::class, $data);
        $form->handleRequest($request);
        $failed = true;
        $message = $this->translator->trans('review.decision.error.save_failed');
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->saveDecision)(new SaveDecisionCommand(
                    $document,
                    $data->decisionId ?? throw new \LogicException('A valid decision requires an identifier.'),
                    $data->versionNumber ?? throw new \LogicException('A valid decision requires a version.'),
                    $data->optionIndexes,
                    $data->expectedOptionIndexes,
                ));
                $failed = false;
                $message = $this->translator->trans('review.decision.status.selection_saved', ['%version%' => $data->versionNumber]);
            } catch (DomainErrors $errors) {
                $message = implode(' ', array_map($this->translator->trans(...), $errors->errors));
            }
        }
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            $this->addFlash($failed ? 'error' : 'success', $message);

            return $this->redirectToRoute('app_document_review', ['projectId' => (string) $project->id, 'documentId' => (string) $document->id]);
        }

        $summary = ($this->showDecisionSummary)(new ShowDecisionSummaryCommand($document, $data->versionNumber));

        return new Response($this->renderView('@Review/_decision_status.stream.html.twig', [
            'message' => $message,
            'failed' => $failed,
            'rows' => $summary->rows,
            'answeredCount' => $summary->answeredCount,
            'restoredBlockId' => null,
            'restoredBlockHtml' => null,
        ]), $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
