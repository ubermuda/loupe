<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Board\Service\CardFeedbackResolver;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A column that turns terminal finishes the cards it holds, so their feedback
 * resolves by the rule a single move follows. It runs inside the flag change's
 * transaction, and it handles a failure the way ResolveFeedbackOnCardMoved does.
 */
#[AsEventListener]
final readonly class ResolveFeedbackOnBoardColumnTerminalChanged
{
    public function __construct(
        private CardFeedbackResolver $feedback,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BoardColumnTerminalChanged $event): void
    {
        if (!$event->terminal || [] === $event->cardIds) {
            return;
        }

        try {
            $this->feedback->resolveFor($event->cardIds, $event->actor);
        } catch (\Throwable $e) {
            if ($e instanceof DbalException || !$this->em->isOpen()) {
                throw $e;
            }
            $this->logger->warning('board.feedback_resolve_failed', [
                'columnId' => $event->columnId,
                'projectId' => (string) $event->project->id,
                'exception' => $e,
            ]);
        }
    }
}
