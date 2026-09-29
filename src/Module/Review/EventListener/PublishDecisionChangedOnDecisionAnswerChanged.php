<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Review\Event\DecisionAnswerChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells every open review page of a document the new answer to one decision.
 * The answer is the same for every viewer, so the message carries it whole.
 */
final readonly class PublishDecisionChangedOnDecisionAnswerChanged
{
    public const string TYPE = 'review.decision_changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    #[AsEventListener]
    public function __invoke(DecisionAnswerChanged $event): void
    {
        $this->publisher->queue($this->topics->forDocument($event->projectId, $event->documentId), [
            'type' => self::TYPE,
            'decisionId' => $event->decisionId,
            'versionNumber' => $event->versionNumber,
            'optionIndexes' => $event->optionIndexes,
            'note' => $event->note,
            'answeredBy' => $event->answeredByName,
            'answeredAt' => $event->answeredAt->format(\DATE_ATOM),
        ]);
    }
}
