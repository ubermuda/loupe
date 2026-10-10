<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Review\Event\DocumentRenamed;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/** A tile tooltip can name the title of a document in review, so a rename redraws the cards that link it. */
#[AsEventListener]
final readonly class DispatchCardChangedOnDocumentRenamed
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(DocumentRenamed $event): void
    {
        foreach ($this->cardDocuments->findCardIdsForDocument($event->documentId) as $cardId) {
            $this->events->dispatch(new CardChanged($event->projectId, Uuid::fromString($cardId), CardChanged::UPDATED, false));
        }
    }
}
