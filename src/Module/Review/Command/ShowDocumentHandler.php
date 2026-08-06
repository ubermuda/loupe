<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\Service\HeadingExtractor;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ShowDocumentHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private DecisionSelectionRepository $decisionSelections,
        private HeadingExtractor $headings,
        private DecisionBlockService $decisionBlocks,
    ) {
    }

    public function __invoke(ShowDocumentCommand $command): ShowDocumentView
    {
        $latest = $this->documentVersions->findLatest($command->document);
        $version = null === $command->versionNumber
            ? $latest
            : $this->version($command->document, $command->versionNumber);

        // Every write on this page targets the current version: the composer posts
        // a comment onto whatever is latest, and the verdict applies to the document
        // as it stands. An older version is therefore rendered as a read-only record
        // of what was discussed then.
        $isLatest = $version->versionNumber === $latest->versionNumber;
        $comments = $this->comments->findByVersion($version);
        $decisions = $this->decisionBlocks->extract($version->renderedHtml);

        return new ShowDocumentView(
            document: $command->document,
            version: $version,
            readOnly: !$isLatest,
            comments: $comments,
            versions: $this->documentVersions->findAllMetaByDocument($command->document),
            headings: $this->headings->extract($version->renderedHtml),
            orphanedCount: count(array_filter($comments, static fn (Comment $c) => $c->orphaned)),
            hasDecisions: [] !== $decisions,
            decisionMarkedHtml: $this->decisionBlocks->withSelections(
                $version->renderedHtml,
                $this->selectedIndexByDecisionId($command->document, $decisions),
                readOnly: !$isLatest,
            ),
        );
    }

    /**
     * Answers are keyed to the document, so an earlier version shows the same ones
     * the latest does — read-only, but shown: a decision rendered blank reads as
     * unanswered, which is a different claim from "answered before this version".
     *
     * Resolved through Decision::resolveIndex, exactly as GetReview does, so the
     * radio the reviewer sees ticked is the option the agent is told.
     *
     * @param list<\App\Module\Review\ValueObject\Decision> $decisions
     *
     * @return array<string, int>
     */
    private function selectedIndexByDecisionId(Document $document, array $decisions): array
    {
        $selections = $this->decisionSelections->findByDocumentIndexedByDecisionId($document);
        $selectedIndexByDecisionId = [];
        foreach ($decisions as $decision) {
            $selection = $selections[$decision->id] ?? null;
            $index = null === $selection ? null : $decision->resolveIndex($selection->optionLabel, $selection->optionIndex);
            if (null !== $index) {
                $selectedIndexByDecisionId[$decision->id] = $index;
            }
        }

        return $selectedIndexByDecisionId;
    }

    private function version(Document $document, int $versionNumber): DocumentVersion
    {
        return $this->documentVersions->findByNumber($document, $versionNumber)
            ?? throw new NotFoundHttpException(\sprintf('Document has no version %d.', $versionNumber));
    }
}
