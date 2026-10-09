<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\StuckPullRequestRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * No card changes when the stuck delay of a ready pull request ends, so this refreshes the cards at that moment.
 * Answers how many cards it refreshed.
 */
final readonly class AnnounceStuckPullRequestsHandler
{
    public const int BATCH = 500;

    public function __construct(
        private StuckPullRequestRepository $stuckPullRequests,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AnnounceStuckPullRequestsCommand $command): int
    {
        $refreshed = 0;
        $announced = [];
        foreach ($this->stuckPullRequests->findDue($this->clock->now(), self::BATCH) as $row) {
            $this->events->dispatch(new CardChanged(Uuid::fromString($row['projectId']), Uuid::fromString($row['cardId']), CardChanged::UPDATED, false));
            $announced[$row['pullRequestId']] = $row['readySince'];
            ++$refreshed;
        }
        foreach ($announced as $pullRequestId => $readySince) {
            $this->stuckPullRequests->markAnnounced($pullRequestId, $readySince);
        }

        return $refreshed;
    }
}
