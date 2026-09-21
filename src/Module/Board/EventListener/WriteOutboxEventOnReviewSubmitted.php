<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Review\ReviewEventType;
use App\Outbox\OutboxWriter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Turns a verdict on a document into a durable outbox row, so the agent bound
 * to the project can act on it. Every verdict writes one, because the rule that
 * picks the verdicts worth acting on belongs to whatever reads the outbox.
 *
 * It lives in Board rather than Review because the payload names the cards the
 * document hangs off, and no module outside Board may read a card.
 *
 * It runs inside SubmitReviewHandler's transaction, so it persists and lets that
 * transaction flush. It must never throw: anything raised here aborts the
 * verdict it was told about. AdvanceCardOnReviewSubmitted is the listener that
 * may, and it is separate for that reason.
 */
#[AsEventListener]
final readonly class WriteOutboxEventOnReviewSubmitted
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private BoardAvailability $board,
        private OutboxWriter $outbox,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        // A board switched off holds no cards to name, and the bridge that
        // reads this event acts on cards alone.
        if (!$this->board->isEnabled()) {
            return;
        }

        $document = $event->review->version->document;
        $cardIds = array_map(
            static fn (CardDocument $link): string => (string) $link->card->id,
            $this->cardDocuments->findForDocument($document),
        );

        // Every key and every value is a contract with the reader of the
        // outbox, so a rename here is a breaking change there. Identifiers and
        // the verdict only: the note a reviewer typed must never reach an agent
        // through a directive.
        $this->outbox->write($document->project, ReviewEventType::REVIEW_SUBMITTED, [
            'type' => ReviewEventType::REVIEW_SUBMITTED,
            'subject' => ['type' => 'document', 'id' => (string) $document->id],
            'projectId' => (string) $document->project->id,
            'verdict' => $event->review->verdict->value,
            'cardIds' => array_values($cardIds),
            'actor' => CardReporter::Human->value,
        ]);
    }
}
