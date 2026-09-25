<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardMoved;
use App\Module\Board\Service\CardFeedbackResolver;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A card that finishes resolves its feedback. A move back out leaves it
 * resolved, and a move between two terminal columns finishes nothing new.
 *
 * It runs inside UpdateCardHandler's transaction. It logs a failure and lets
 * the move commit. A database failure or a closed entity manager fails the
 * move instead: a failed query aborts the transaction, and the commit would
 * then roll back while the move reports success. It runs after the epic
 * listener, whose refusal rolls the move back before any comment resolves.
 */
#[AsEventListener(priority: -10)]
final readonly class ResolveFeedbackOnCardMoved
{
    public function __construct(
        private CardFeedbackResolver $feedback,
        private EntityManagerInterface $em,
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
            if ($e instanceof DbalException || !$this->em->isOpen()) {
                throw $e;
            }
            $this->logger->warning('board.feedback_resolve_failed', [
                'cardId' => (string) $card->id,
                'projectId' => (string) $card->project->id,
                'exception' => $e,
            ]);
        }
    }
}
