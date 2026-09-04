<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SelectDecisionOptionCommand;
use App\Module\Review\Command\SelectDecisionOptionHandler;
use App\Module\Review\Command\ShowDecisionSummaryCommand;
use App\Module\Review\Command\ShowDecisionSummaryHandler;
use App\Module\Review\Command\ShowPersistedDecisionBlockCommand;
use App\Module\Review\Command\ShowPersistedDecisionBlockHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\SelectDecisionOptionFormType;
use App\Module\Review\Form\SelectDecisionOptionRequest;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\DecisionBlockService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/decisions',
    name: 'app_document_decision_select',
    methods: ['POST'],
)]
final class SelectDecisionOptionController extends AppController
{
    /**
     * How much of an option label the status line repeats.
     *
     * The region is `aria-live`, so a whole paragraph of an option would be read
     * out on every click.
     */
    private const int ANNOUNCED_OPTION_LENGTH = 60;

    public function __construct(
        private readonly SelectDecisionOptionHandler $selectDecisionOption,
        private readonly ShowPersistedDecisionBlockHandler $showPersistedDecisionBlock,
        private readonly ShowDecisionSummaryHandler $showDecisionSummary,
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
            $message = $this->refusal($this->translator->trans('review.decision.error.save_failed'), $data->versionNumber);
        } else {
            try {
                $selection = ($this->selectDecisionOption)(new SelectDecisionOptionCommand(
                    document: $document,
                    decisionId: $data->decisionId ?? throw new \LogicException('decisionId required after validation'),
                    optionIndex: $data->optionIndex ?? throw new \LogicException('optionIndex required after validation'),
                    displayedVersionNumber: $data->versionNumber ?? throw new \LogicException('versionNumber required after validation'),
                ));
                // The stored label and version, never the submitted ones: the
                // handler is what decides which option and which version the
                // answer actually landed against.
                $message = $this->translator->trans('review.decision.status.saved', [
                    '%option%' => self::announcedOption($selection->optionLabel),
                    '%version%' => $selection->versionNumber,
                ]);
                $failed = false;
            } catch (DomainErrors $e) {
                // Deliberately not mapped onto the form fields. The form is
                // hidden and renders no field a reviewer can see, so a
                // form_errors() output would be invisible; the status line is
                // the only surface. The field names in $e->errors are dropped
                // and only the messages survive.
                $message = $this->refusal(implode(' ', array_map(
                    fn (string $key): string => $this->translator->trans($key),
                    $e->errors,
                )), $data->versionNumber);
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

        // A refused submission leaves the clicked radio checked in the browser,
        // so the block is streamed back from what is stored. On success it is
        // not: the reviewer's click already matches the database, and replacing
        // the block would discard live comment highlights for nothing.
        $restoredBlockHtml = $failed
            ? ($this->showPersistedDecisionBlock)(new ShowPersistedDecisionBlockCommand(
                $document,
                $data->decisionId,
                $data->versionNumber,
            ))->blockHtml
            : null;

        // Read back after the write, so the panel and its running total report
        // what is stored rather than what was asked for — on the refused path
        // they must show the answer that survived, not the one that did not.
        // Against the version the page was rendered from, which is what the
        // reviewer is still looking at when a stale answer is refused.
        $summary = ($this->showDecisionSummary)(
            new ShowDecisionSummaryCommand($document, $data->versionNumber),
        );

        return new Response(
            $this->renderView('@Review/_decision_status.stream.html.twig', [
                'message' => $message,
                'failed' => $failed,
                'rows' => $summary->rows,
                'answeredCount' => $summary->answeredCount,
                'restoredBlockId' => null === $restoredBlockHtml
                    ? null
                    : DecisionBlockService::blockElementId($data->decisionId ?? ''),
                'restoredBlockHtml' => $restoredBlockHtml,
            ]),
            $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }

    /**
     * A refusal, framed the way a saved answer is: the outcome, then the version
     * the reviewer is still looking at, then why.
     *
     * The submitted version is the honest one to name here. A refused answer
     * belongs to the list the reviewer clicked, which is what the page still
     * shows.
     */
    private function refusal(string $reason, ?int $versionNumber): string
    {
        if (null === $versionNumber) {
            return $reason;
        }

        return $this->translator->trans('review.decision.status.not_saved', [
            '%reason%' => $reason,
            '%version%' => $versionNumber,
        ]);
    }

    private static function announcedOption(string $label): string
    {
        if (mb_strlen($label) <= self::ANNOUNCED_OPTION_LENGTH) {
            return $label;
        }

        return rtrim(mb_substr($label, 0, self::ANNOUNCED_OPTION_LENGTH)).'…';
    }
}
