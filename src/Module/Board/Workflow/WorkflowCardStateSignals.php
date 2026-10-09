<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Service\CardStateCode;
use App\Module\Board\Service\CardStateReason;
use App\Module\Board\Service\CardStateSignalsInterface;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\CardStateHints;
use Symfony\Component\Uid\Uuid;

/**
 * Two facts that only the workflow can read: a stage document in review, which is a document with a tag
 * that the rules of the card's slot read, and a move rule that an open blocker alone holds.
 */
final readonly class WorkflowCardStateSignals implements CardStateSignalsInterface
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private CardStateHints $hints,
    ) {
    }

    #[\Override]
    public function signalsFor(Project $project, array $cards): array
    {
        $ids = array_map(static fn (Card $card): string => (string) $card->id, $cards);

        $signals = [];
        foreach ($this->documentsInReview($project, $ids) as $cardId => $reason) {
            $signals[$cardId][] = $reason;
        }
        foreach ($this->hints->blockerHolds($ids) as $hold) {
            $signals[$hold->cardId][] = new CardStateReason(
                CardStateCode::HeldByBlocker,
                ['%number%' => $hold->blockerNumber, '%title%' => $hold->blockerTitle],
                $hold->since,
            );
        }

        return $signals;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, CardStateReason> card id => the stage document in review that waited longest
     */
    private function documentsInReview(Project $project, array $ids): array
    {
        $rows = $this->cardDocuments->findInReviewForCards($project, array_map(Uuid::fromString(...), $ids));
        if ([] === $rows) {
            return [];
        }
        $stageTags = $this->hints->stageDocumentTags($project->requireId());
        if (null === $stageTags) {
            return [];
        }

        $found = [];
        foreach ($rows as $row) {
            $card = $row['link']->card;
            $tags = array_map(static fn (Tag $tag): string => $tag->name, $row['link']->document->tags->toArray());
            if ([] === array_intersect($stageTags->forColumn((string) $card->column->id, $card->column->backlog), $tags)) {
                continue;
            }
            $cardId = (string) $card->id;
            $kept = $found[$cardId] ?? null;
            if (null === $kept || (null !== $row['versionAt'] && (null === $kept->since || $row['versionAt'] < $kept->since))) {
                $found[$cardId] = new CardStateReason(
                    CardStateCode::DocumentInReview,
                    ['%title%' => $row['link']->document->title],
                    $row['versionAt'],
                );
            }
        }

        return $found;
    }
}
