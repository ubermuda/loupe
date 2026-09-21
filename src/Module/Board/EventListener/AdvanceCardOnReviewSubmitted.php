<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\LifecycleStages;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\ReviewSubmitted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Moves a card to the next column when a person approves the document it hangs
 * off. The approval stays the person's act, and the move is its consequence.
 *
 * This listener may throw, unlike WriteOutboxEventOnReviewSubmitted beside it.
 * It runs inside SubmitReviewHandler's transaction, and the owner chose the
 * approval and the move to commit or fail together, so a card's column and a
 * document's status can never disagree. A failure here rolls the approval back,
 * which the reviewer sees as a refused click.
 *
 * Nesting UpdateCardHandler's own transaction is safe. DBAL opens a SAVEPOINT
 * above the first level and releases it on commit, so the outer transaction
 * decides durability and an inner failure propagates.
 */
#[AsEventListener]
final readonly class AdvanceCardOnReviewSubmitted
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private BoardColumnRepository $columns,
        private LifecycleStages $stages,
        private UpdateCardHandler $updateCard,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        if (Verdict::Approved !== $event->review->verdict || !$this->board->isEnabled()) {
            return;
        }

        $document = $event->review->version->document;
        $stage = $this->stages->forDocument($document);
        if (null === $stage) {
            return;
        }

        // A document belongs to one project, and a card links to a document of
        // its own project alone, so one lookup serves every card below.
        $target = $this->columnOf($document->project, $stage['to']);
        // A board without the column this stage leads to does not run this
        // lifecycle, which is no reason to refuse the approval.
        if (null === $target) {
            return;
        }

        foreach ($this->cardDocuments->findForDocument($document) as $link) {
            if ($link->card->column->slug !== $stage['from']) {
                continue;
            }

            ($this->updateCard)(new UpdateCardCommand(
                card: $link->card,
                actor: CardReporter::System,
                column: $target,
            ));
        }
    }

    private function columnOf(Project $project, string $slug): ?BoardColumn
    {
        foreach ($this->columns->findForProject($project) as $column) {
            if ($column->slug === $slug) {
                return $column;
            }
        }

        return null;
    }
}
