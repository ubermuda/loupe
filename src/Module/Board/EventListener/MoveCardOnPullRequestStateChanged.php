<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\MoveCardsOnPullRequestStateCommand;
use App\Module\Board\Command\MoveCardsOnPullRequestStateHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Forge\Event\PullRequestStateChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Runs after PublishPullRequestEventsOnStateChanged, so the outbox holds the fact before the move it caused. */
#[AsEventListener(priority: -5)]
final readonly class MoveCardOnPullRequestStateChanged
{
    public function __construct(
        private MoveCardsOnPullRequestStateHandler $move,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        ($this->move)(new MoveCardsOnPullRequestStateCommand($event->pullRequest, $event->previous, $event->current));
    }
}
