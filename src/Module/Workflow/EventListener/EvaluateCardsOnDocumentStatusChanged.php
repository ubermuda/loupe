<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class EvaluateCardsOnDocumentStatusChanged
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private CardRepository $cards,
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(DocumentStatusChanged $event): void
    {
        $cardIds = $this->cardDocuments->findCardIdsForDocument($event->documentId);
        $childIds = array_merge(...array_map(fn (string $id): array => $this->cards->findChildIds(Uuid::fromString($id)), $cardIds));
        $this->trigger->forCards(array_values(array_unique([...$cardIds, ...$childIds])));
    }
}
