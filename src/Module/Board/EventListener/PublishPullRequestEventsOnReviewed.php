<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\PublishPullRequestEventsCommand;
use App\Module\Board\Command\PublishPullRequestEventsHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Forge\Event\PullRequestReviewed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A throw here rolls back the read Forge stored, so a retry publishes again. */
#[AsEventListener]
final readonly class PublishPullRequestEventsOnReviewed
{
    public function __construct(
        private PublishPullRequestEventsHandler $publish,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(PullRequestReviewed $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        ($this->publish)(new PublishPullRequestEventsCommand($event->pullRequest, $event->snapshot, $event->snapshot, reviewed: true));
    }
}
