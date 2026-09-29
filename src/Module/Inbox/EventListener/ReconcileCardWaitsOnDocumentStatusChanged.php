<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Review\Event\DocumentStatusChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ReconcileCardWaitsOnDocumentStatusChanged
{
    public function __construct(
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(DocumentStatusChanged $event): void
    {
        $this->trigger->forDocument($event->projectId, $event->documentId);
    }
}
