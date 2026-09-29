<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Command\ShowDecisionSummaryCommand;
use App\Module\Review\Command\ShowDecisionSummaryHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\SaveDecisionAnswerFormType;
use App\Module\Review\Form\SaveDecisionAnswerRequest;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/decisions/answer',
    name: 'app_document_decision_answer',
    methods: ['POST'],
)]
final class SaveDecisionAnswerController extends AppController
{
    public function __construct(
        private readonly SaveDecisionAnswerHandler $saveDecisionAnswer,
        private readonly ShowDecisionSummaryHandler $showDecisionSummary,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        Request $request,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $data = new SaveDecisionAnswerRequest();
        $form = $this->createForm(SaveDecisionAnswerFormType::class, $data);
        $form->handleRequest($request);
        $failed = true;
        // The form is hidden, so the status line is the only place a field error shows.
        $formErrors = array_map(
            static fn (FormError $error): string => $error->getMessage(),
            iterator_to_array($form->getErrors(deep: true), false),
        );
        $message = [] === $formErrors ? $this->translator->trans('review.decision.error.save_failed') : implode(' ', $formErrors);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $result = ($this->saveDecisionAnswer)(new SaveDecisionAnswerCommand(
                    $document,
                    $data->decisionId ?? throw new \LogicException('A valid decision requires an identifier.'),
                    $data->versionNumber ?? throw new \LogicException('A valid decision requires a version.'),
                    $data->optionIndexes,
                    $data->note,
                    $data->clear,
                    $user,
                ));
                $failed = false;
                $message = $this->translator->trans($result->cleared ? 'review.decision.status.answer_cleared' : 'review.decision.status.answer_saved');
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
            'page' => $document->id.'/'.$data->versionNumber,
            'message' => $message,
            'failed' => $failed,
            'rows' => $summary->rows,
            'answeredCount' => $summary->answeredCount,
        ]), $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
