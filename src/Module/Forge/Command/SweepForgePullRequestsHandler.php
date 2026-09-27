<?php

declare(strict_types=1);

namespace App\Module\Forge\Command;

use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The backstop for a delivery the forge never sent. A row queued twice is read
 * once, because the refresh skips a row read after its request.
 */
final readonly class SweepForgePullRequestsHandler
{
    public const int BATCH_SIZE = 500;

    // A minute under the cron interval, so a row read by the previous tick is due at the next one.
    private const string STALE_AFTER = '-9 minutes';

    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private int $batchSize = self::BATCH_SIZE,
    ) {
    }

    /** @return int how many refreshes it queued */
    public function __invoke(SweepForgePullRequestsCommand $command): int
    {
        $now = $this->clock->now();
        $staleBefore = $now->modify(self::STALE_AFTER);
        $queued = 0;
        $afterId = null;
        do {
            $ids = $this->forgePullRequests->findIdsOfStaleOpen($staleBefore, $afterId, $this->batchSize);
            foreach ($ids as $id) {
                $this->bus->dispatch(new RefreshPullRequestState((string) $id, $now));
                ++$queued;
                $afterId = $id;
            }
        } while (\count($ids) === $this->batchSize);

        return $queued;
    }
}
