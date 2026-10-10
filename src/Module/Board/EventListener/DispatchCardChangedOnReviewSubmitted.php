<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Review\Event\ReviewSubmitted;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/** A verdict ends the review of a document, which changes the state of the cards it links. The event runs inside the verdict transaction, so the change is an update. */
#[AsEventListener]
final readonly class DispatchCardChangedOnReviewSubmitted
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        $document = $event->review->version->document;
        foreach ($this->cardDocuments->findCardIdsForDocument($document->id ?? throw new \LogicException('Document has no id.')) as $cardId) {
            $this->events->dispatch(new CardChanged(
                $document->project->id ?? throw new \LogicException('Project has no id.'),
                Uuid::fromString($cardId),
                CardChanged::UPDATED,
                false,
            ));
        }
    }
}
