<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Review\Event\ReviewSubmitted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A verdict takes the document out of review and completes the open agent
 * review items of the document, so this one message covers both changes.
 */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnReviewSubmitted
{
    public function __construct(
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        $document = $event->review->version->document;
        $this->trigger->forDocument(
            $document->project->id ?? throw new \LogicException('Project has no id.'),
            $document->id ?? throw new \LogicException('Document has no id.'),
        );
    }
}
