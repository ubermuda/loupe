<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\PublishPullRequestEventsCommand;
use App\Module\Board\Command\PublishPullRequestEventsHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Forge\Event\PullRequestStateChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A throw here rolls back the state Forge stored, so the next read publishes again. */
#[AsEventListener]
final readonly class PublishPullRequestEventsOnStateChanged
{
    public function __construct(
        private PublishPullRequestEventsHandler $publish,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        ($this->publish)(new PublishPullRequestEventsCommand($event->pullRequest, $event->previous, $event->current));
    }
}
