<?php

declare(strict_types=1);

namespace App\Module\Board\Scheduler;

use App\Module\Board\Command\AnnounceStuckPullRequestsCommand;
use App\Module\Board\Command\AnnounceStuckPullRequestsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Refreshes the cards whose ready pull request just passed the stuck delay of its board.
 * `app:announce-stuck-pull-requests` is the manual backstop.
 */
#[AsCronTask('%app.board.announce_stuck_schedule%')]
final readonly class AnnounceStuckPullRequestsTask
{
    public function __construct(
        private AnnounceStuckPullRequestsHandler $announceStuckPullRequests,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $refreshed = ($this->announceStuckPullRequests)(new AnnounceStuckPullRequestsCommand());

        if ($refreshed > 0) {
            $this->logger->info('board.stuck_announced', ['cards' => $refreshed]);
        }
    }
}
