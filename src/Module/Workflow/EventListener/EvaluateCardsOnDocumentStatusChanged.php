<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Workflow\Engine\EngineSwitch;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnDocumentStatusChanged
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private EvaluationTrigger $trigger,
        private EngineSwitch $engine,
    ) {
    }

    public function __invoke(DocumentStatusChanged $event): void
    {
        // The read below runs on every event, so an engine that is off skips it.
        if (!$this->engine->isOn()) {
            return;
        }

        $this->trigger->forCards($this->cardDocuments->findCardIdsForDocument($event->documentId));
    }
}
