<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class EvaluateCardsOnReviewSubmitted
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        $cardIds = $this->cardDocuments->findCardIdsForDocument(
            $event->review->version->document->id ?? throw new \LogicException('Document has no id.'),
        );
        $childIds = array_merge(...array_map(fn (string $id): array => $this->cards->findChildIds(Uuid::fromString($id)), $cardIds));
        $this->trigger->forCards(array_values(array_unique([...$cardIds, ...$childIds])));
    }
}
