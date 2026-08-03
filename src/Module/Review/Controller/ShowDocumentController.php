<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\AddCommentFormType;
use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Form\SelectDecisionOptionFormType;
use App\Module\Review\Form\SelectDecisionOptionRequest;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\Service\HeadingExtractor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review',
    name: 'app_document_review',
    methods: ['GET'],
)]
// A revision carries only the still-open comments forward, so a thread resolved
// before it stays on the version it was written on. Without a way to render that
// version the whole discussion becomes unreachable the moment the document is
// revised. The unnumbered route above keeps meaning "latest", so every existing
// link to it is unaffected.
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/versions/{versionNumber}',
    name: 'app_document_review_version',
    requirements: ['versionNumber' => '\d+'],
    methods: ['GET'],
)]
final class ShowDocumentController extends AppController
{
    public function __construct(
        private readonly DocumentVersionRepository $documentVersions,
        private readonly CommentRepository $comments,
        private readonly DecisionSelectionRepository $decisionSelections,
        private readonly HeadingExtractor $headings,
        private readonly DecisionBlockService $decisionBlocks,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        ?int $versionNumber = null,
    ): Response {
        $latest = $this->documentVersions->findLatest($document);
        $version = null === $versionNumber
            ? $latest
            : $this->documentVersions->findByNumber($document, $versionNumber)
                ?? throw $this->createNotFoundException(sprintf('Document has no version %d.', $versionNumber));

        // Every write on this page targets the current version: the composer posts
        // a comment onto whatever is latest, and the verdict applies to the document
        // as it stands. An older version is therefore rendered as a read-only record
        // of what was discussed then.
        $isLatest = $version->versionNumber === $latest->versionNumber;
        $comments = $this->comments->findByVersion($version);

        $addCommentForm = $this->createForm(AddCommentFormType::class, new AddCommentRequest(), [
            'action' => $this->generateUrl('app_comment_add', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]),
            'method' => 'POST',
        ]);

        $selectDecisionForm = $this->createForm(SelectDecisionOptionFormType::class, new SelectDecisionOptionRequest(), [
            'action' => $this->generateUrl('app_document_decision_select', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]),
            'method' => 'POST',
        ]);

        // Answers are keyed to the document, so an earlier version shows the same
        // ones the latest does — read-only, but shown: a decision rendered blank
        // reads as unanswered, which is a different claim from "answered before
        // this version".
        //
        // Resolved through the recorded label, exactly as GetReview does. Ticking
        // the recorded index instead would, after a revision that reordered a
        // block, show the reviewer an option they never chose.
        $decisions = $this->decisionBlocks->extract($version->renderedHtml);
        $selections = $this->decisionSelections->findByDocumentIndexedByDecisionId($document);
        $selectedIndexByDecisionId = [];
        foreach ($decisions as $decision) {
            $selection = $selections[$decision->id] ?? null;
            $index = null === $selection ? null : $decision->indexOf($selection->optionLabel);
            if (null !== $index) {
                $selectedIndexByDecisionId[$decision->id] = $index;
            }
        }

        return $this->render('@Review/show_document.html.twig', [
            'document' => $document,
            'version' => $version,
            'versions' => $this->documentVersions->findAllMetaByDocument($document),
            'readOnly' => !$isLatest,
            'comments' => $comments,
            'headings' => $this->headings->extract($version->renderedHtml),
            'orphanedCount' => count(array_filter($comments, static fn (Comment $c) => $c->orphaned)),
            'addCommentForm' => $addCommentForm,
            'selectDecisionForm' => $selectDecisionForm,
            'hasDecisions' => [] !== $decisions,
            'decisionMarkedHtml' => $this->decisionBlocks->withSelections(
                $version->renderedHtml,
                $selectedIndexByDecisionId,
                readOnly: !$isLatest,
            ),
        ]);
    }
}
