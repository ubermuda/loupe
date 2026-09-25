<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardMoved;
use App\Module\Board\Service\CardFeedbackResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A card that finishes resolves its feedback. A move back out leaves it
 * resolved, and a move between two terminal columns finishes nothing new.
 *
 * It runs inside UpdateCardHandler's transaction and must never throw, so it
 * logs a failure and lets the move commit. It runs after the epic listener,
 * whose refusal rolls the move back before any comment is resolved.
 */
#[AsEventListener(priority: -10)]
final readonly class ResolveFeedbackOnCardMoved
{
    public function __construct(
        private CardFeedbackResolver $feedback,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $card = $event->card;
        if (!$card->column->terminal || $event->move->fromColumn->terminal) {
            return;
        }

        try {
            $this->feedback->resolveFor([(string) $card->id], $event->actor);
        } catch (\Throwable $e) {
            $this->logger->warning('board.feedback_resolve_failed', [
                'cardId' => (string) $card->id,
                'projectId' => (string) $card->project->id,
                'exception' => $e,
            ]);
        }
    }
}
