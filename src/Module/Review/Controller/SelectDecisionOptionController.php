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
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\DecisionBlockService;
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
        private readonly DocumentVersionRepository $documentVersions,
        private readonly DecisionSelectionRepository $decisionSelections,
        private readonly DecisionBlockService $decisionBlocks,
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

        // A refused submission leaves the clicked radio checked in the browser,
        // so the block is streamed back from what is stored. On success it is
        // not: the reviewer's click already matches the database, and replacing
        // the block would discard live comment highlights for nothing.
        $restoredBlockHtml = $failed ? $this->persistedBlockHtml($document, $data) : null;

        return new Response(
            $this->renderView('@Review/_decision_status.stream.html.twig', [
                'message' => $message,
                'failed' => $failed,
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
     * The submitted block as the database has it, or null when there is nothing
     * trustworthy to put back.
     *
     * Rendered from the version the form named rather than the current one: the
     * reviewer's page still shows that version's options, and swapping in a
     * newer block would leave one part of the document ahead of the rest.
     *
     * A decision with no stored answer yields a block with nothing checked,
     * which is the point — a first-ever answer that fails must clear the radio,
     * not fall back to some earlier one.
     */
    private function persistedBlockHtml(Document $document, SelectDecisionOptionRequest $data): ?string
    {
        // Both come off an invalid submission on the CSRF path, so neither is
        // trusted: an unparseable id or an unknown version simply means the
        // status line goes back alone.
        if (null === $data->decisionId || null === $data->versionNumber) {
            return null;
        }

        $version = $this->documentVersions->findByNumber($document, $data->versionNumber);
        if (null === $version) {
            return null;
        }

        $blockHtml = $this->decisionBlocks->blockHtml($version->renderedHtml, $data->decisionId);
        if (null === $blockHtml) {
            return null;
        }

        $selected = [];
        foreach ($this->decisionBlocks->extract($blockHtml) as $decision) {
            $selection = $this->decisionSelections->findOneByDocumentAndDecisionId($document, $decision->id);
            $index = null === $selection ? null : $decision->resolveIndex($selection->optionLabel, $selection->optionIndex);
            if (null !== $index) {
                $selected[$decision->id] = $index;
            }
        }

        return $this->decisionBlocks->withSelections($blockHtml, $selected, readOnly: false);
    }
}
