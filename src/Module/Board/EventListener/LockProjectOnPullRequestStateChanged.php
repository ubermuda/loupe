<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Forge\Event\PullRequestStateChanged;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Takes the project lock before the listeners of PullRequestStateChanged that
 * write card rows, the order UpdateCardHandler uses. Two reads of one pull
 * request then cannot record the same event twice in parallel. Forge has read
 * the forge by now, so no HTTP call runs under the lock.
 */
#[AsEventListener(priority: 10)]
final readonly class LockProjectOnPullRequestStateChanged
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        $this->em->lock($event->pullRequest->project, LockMode::PESSIMISTIC_WRITE);
    }
}
