<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnReviewSubmitted
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        $this->trigger->forCards($this->cardDocuments->findCardIdsForDocument(
            $event->review->version->document->id ?? throw new \LogicException('Document has no id.'),
        ));
    }
}
