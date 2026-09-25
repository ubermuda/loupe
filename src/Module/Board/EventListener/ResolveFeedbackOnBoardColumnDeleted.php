<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Service\CardFeedbackResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A column delete moves its cards in bulk and dispatches no CardMoved, so the
 * cards it finishes resolve their feedback from here, by the rule a single
 * move follows. It runs inside the delete's transaction and must never throw.
 */
#[AsEventListener]
final readonly class ResolveFeedbackOnBoardColumnDeleted
{
    public function __construct(
        private CardFeedbackResolver $feedback,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BoardColumnDeleted $event): void
    {
        if (!$event->targetTerminal || $event->terminal || [] === $event->movedCardIds) {
            return;
        }

        try {
            $this->feedback->resolveFor($event->movedCardIds, $event->actor);
        } catch (\Throwable $e) {
            $this->logger->warning('board.feedback_resolve_failed', [
                'columnId' => $event->columnId,
                'projectId' => (string) $event->project->id,
                'exception' => $e,
            ]);
        }
    }
}
