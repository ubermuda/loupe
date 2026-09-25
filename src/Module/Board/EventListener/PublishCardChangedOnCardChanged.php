<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Event\CardChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells every open board of a project which card changed. The message carries
 * no card content, because what a board shows depends on who is looking at it.
 */
final readonly class PublishCardChangedOnCardChanged
{
    public const string TYPE = 'board.card_changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    #[AsEventListener]
    public function __invoke(CardChanged $event): void
    {
        $this->publisher->queue($this->topics->forBoard($event->projectId), [
            'type' => self::TYPE,
            'cardId' => (string) $event->cardId,
            'change' => $event->change,
            'contentChanged' => $event->contentChanged,
        ]);
    }
}
