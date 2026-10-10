<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Review\Event\DocumentStatusChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/** A document that enters or leaves review changes the state of the cards it links. */
#[AsEventListener]
final readonly class DispatchCardChangedOnDocumentStatusChanged
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(DocumentStatusChanged $event): void
    {
        foreach ($this->cardDocuments->findCardIdsForDocument($event->documentId) as $cardId) {
            $this->events->dispatch(new CardChanged($event->projectId, Uuid::fromString($cardId), CardChanged::UPDATED, false));
        }
    }
}
