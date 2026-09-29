<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\PullRequestMoveTriggers;
use App\Module\Forge\Event\PullRequestStateChanged;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Takes the project lock before PublishPullRequestEventsOnStateChanged locks an
 * automation row, the order UpdateCardHandler uses, so the two cannot deadlock.
 * Forge has read the forge by now, so no HTTP call runs under the lock.
 */
#[AsEventListener(priority: 10)]
final readonly class LockProjectOnPullRequestStateChanged
{
    public function __construct(
        private EntityManagerInterface $em,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        if ($this->board->isEnabled() && PullRequestMoveTriggers::mayMove($event->previous, $event->current)) {
            $this->em->lock($event->pullRequest->project, LockMode::PESSIMISTIC_WRITE);
        }
    }
}
