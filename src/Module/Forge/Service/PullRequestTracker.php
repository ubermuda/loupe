<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The one way another module keeps the state of a pull request current. Every
 * write runs on the caller's connection, and the transport is Doctrine, so a
 * caller that rolls back also drops the queued refresh.
 */
final readonly class PullRequestTracker
{
    /** A merge into the base takes a moment to reach the forge's mergeability answer. */
    private const int BASE_DELAY_MILLISECONDS = 30_000;

    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestStateReaders $readers,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function track(Project $project, string $forge, string $repository, int $number): void
    {
        if (null === $this->readers->for($forge)) {
            return;
        }

        $this->queue($this->forgePullRequests->insertIfMissing(self::projectId($project), $forge, $repository, $number));
    }

    public function untrack(Project $project, string $forge, string $repository, int $number): void
    {
        $this->forgePullRequests->deleteByKey(self::projectId($project), $forge, $repository, $number);
    }

    public function refresh(Project $project, string $forge, string $repository, int $number): void
    {
        foreach ($this->forgePullRequests->findOpenIds(self::projectId($project), $forge, $repository, ['number' => $number]) as $id) {
            $this->queue($id);
        }
    }

    public function refreshHead(Project $project, string $forge, string $repository, string $sha): void
    {
        foreach ($this->forgePullRequests->findOpenIds(self::projectId($project), $forge, $repository, ['headSha' => $sha]) as $id) {
            $this->queue($id);
        }
    }

    public function refreshBase(Project $project, string $forge, string $repository, string $branch): void
    {
        foreach ($this->forgePullRequests->findOpenIds(self::projectId($project), $forge, $repository, ['baseBranch' => $branch]) as $id) {
            $this->queue($id, self::BASE_DELAY_MILLISECONDS);
        }
    }

    private function queue(Uuid $id, int $delayMilliseconds = 0): void
    {
        $this->bus->dispatch(
            new RefreshPullRequestState((string) $id, $this->clock->now()),
            $delayMilliseconds > 0 ? [new DelayStamp($delayMilliseconds)] : [],
        );
    }

    private static function projectId(Project $project): Uuid
    {
        return $project->id ?? throw new \LogicException('A tracked project is persisted.');
    }
}
