<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Messenger\SyncNextPullRequest;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\SyncLine;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestBranchUpdaters;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Asks the forge to update the branch of the next approved pull request that
 * is behind its base. The lock on the settings row lets one pass per project
 * pick at a time. The forge call runs outside any transaction, so a slow forge
 * holds no lock.
 */
final readonly class SyncNextPullRequestHandler
{
    /** Guards against a nonsense header only. GitHub can ask for more than an hour. */
    private const int MAX_RETRY_DELAY_SECONDS = 86_400;

    private const int MAX_RETRIES = 3;

    /** The first wait after a transient failure with no Retry-After. Each later retry waits twice as long. */
    private const int BACKOFF_SECONDS = 30;

    private const string RETRIES_EXHAUSTED = 'retries_exhausted';

    /** Lets the pass after the lifetime find the marker stale, whatever the clock skew between workers. */
    private const int EXPIRY_MARGIN_SECONDS = 30;

    public function __construct(
        private ProjectRepository $projects,
        private BoardAvailability $board,
        private BoardAutomationSettingsRepository $boardAutomationSettings,
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestBranchUpdaters $updaters,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SyncNextPullRequestCommand $command): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }
        $project = $this->projects->find($command->projectId);
        if (null === $project) {
            return;
        }

        $pullRequest = null;
        $attempt = 0;
        if (null !== $command->retryPullRequestId && null !== $command->retrySha) {
            $pullRequest = $this->em->wrapInTransaction(fn (): ?ForgePullRequest => $this->retryTarget($project, $command));
            $attempt = $command->attempt;
        }
        // A retry that lost its target runs as a plain pass, so the line still moves.
        if (null === $pullRequest) {
            $pullRequest = $this->em->wrapInTransaction(fn (): ?ForgePullRequest => $this->pick($project, $command->projectId));
            $attempt = 0;
        }
        if (null === $pullRequest) {
            return;
        }

        $this->update($pullRequest, $attempt, $command->projectId);
    }

    private function update(ForgePullRequest $pullRequest, int $attempt, Uuid $projectId): void
    {
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');
        $sha = $pullRequest->syncFromSha ?? throw new \LogicException('The pick carries its marker.');

        $updater = $this->updaters->for($pullRequest->forge);
        if (null === $updater) {
            $this->fail($id, $sha, 'no_updater', $projectId);

            return;
        }

        try {
            $updater->update($pullRequest, $sha);
        } catch (PullRequestSyncFailed $e) {
            if ($e->permanent) {
                $this->fail($id, $sha, $e->cause, $projectId);
            } elseif ($attempt >= self::MAX_RETRIES) {
                $this->fail($id, $sha, self::RETRIES_EXHAUSTED, $projectId);
            } else {
                $this->retryLater($id, $sha, $attempt + 1, $e, $projectId);
            }

            return;
        }

        // GitHub accepts the update before the merge commit exists, so a later read records the sync on the cards.
        $this->logger->info('board.pull_request_sync_requested', [
            'pullRequestId' => (string) $id,
            'projectId' => (string) $projectId,
            'forge' => $pullRequest->forge,
            'repository' => $pullRequest->repository,
            'pullRequestNumber' => $pullRequest->number,
            'fromSha' => $sha,
            'attempt' => $attempt,
        ]);
    }

    /**
     * Keeps the marker and dates it at the retry, so the line stays held and the
     * timeout counts from the retry. The handler owns the retry, so a wait longer
     * than the marker lifetime never lets a timeout pass call the forge early.
     */
    private function retryLater(Uuid $id, string $sha, int $attempt, PullRequestSyncFailed $e, Uuid $projectId): void
    {
        $delay = null === $e->retryAfterSeconds
            ? self::BACKOFF_SECONDS * 2 ** ($attempt - 1)
            : min(max(0, $e->retryAfterSeconds), self::MAX_RETRY_DELAY_SECONDS);
        $queued = $this->em->wrapInTransaction(function () use ($id, $sha, $attempt, $delay, $projectId): bool {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null === $row || $row->syncFromSha !== $sha) {
                return false;
            }
            $row->syncRequestedAt = $this->clock->now()->modify(\sprintf('+%d seconds', $delay));
            $this->bus->dispatch(new SyncNextPullRequest($projectId, $id, $sha, $attempt), [new DelayStamp($delay * 1000)]);

            return true;
        });
        $this->logger->warning('board.pull_request_sync_retried', [
            'pullRequestId' => (string) $id,
            'cause' => $e->cause,
            'attempt' => $attempt,
            'delaySeconds' => $delay,
            'queued' => $queued,
        ]);
    }

    /** The pull request of a retry, while the setting is on and Loupe still waits to update the same head. */
    private function retryTarget(Project $project, SyncNextPullRequestCommand $command): ?ForgePullRequest
    {
        $settings = $this->boardAutomationSettings->findOneByProjectForUpdate($project);
        if (null === $settings || !$settings->enabled || !$settings->syncBehind || null === $command->retryPullRequestId) {
            return null;
        }
        $row = $this->forgePullRequests->findForUpdate($command->retryPullRequestId);
        if (null === $row || $row->syncFromSha !== $command->retrySha || $row->headSha !== $command->retrySha) {
            return null;
        }

        $row->syncRequestedAt = $this->clock->now();
        $this->em->flush();
        $this->queueTimeoutPass($command->projectId);

        return $row;
    }

    private function pick(Project $project, Uuid $projectId): ?ForgePullRequest
    {
        $settings = $this->boardAutomationSettings->findOneByProjectForUpdate($project);
        if (null === $settings || !$settings->enabled || !$settings->syncBehind) {
            return null;
        }

        $now = $this->clock->now();
        $line = new SyncLine($this->forgePullRequests->findOpenForProject($projectId), $now);

        // Each row is locked and read again, because a forge read may have moved its head since the line was read.
        foreach ($line->timedOut as $row) {
            $marker = $row->syncFromSha;
            $locked = $this->forgePullRequests->findForUpdate($row->id ?? throw new \LogicException('A stored pull request has an id.'));
            if (null !== $locked && null !== $marker && $locked->syncFromSha === $marker) {
                $locked->syncFailedReason = SyncLine::TIMEOUT;
                $locked->syncFromSha = null;
                $locked->syncRequestedAt = null;
                $this->em->flush();
                $this->logger->warning('board.pull_request_sync_timed_out', ['pullRequestId' => (string) $locked->id, 'fromSha' => $marker]);
            }
        }

        // The last reread fires no event when the mergeability stays unknown, so nothing else would free the line.
        if (null !== $line->holderRereadAt) {
            // One second past the end, so the pass finds the window closed. A closed window queues nothing, so this cannot loop.
            $delay = $line->holderRereadAt->getTimestamp() - $now->getTimestamp() + 1;
            $this->bus->dispatch(new SyncNextPullRequest($projectId), [new DelayStamp($delay * 1000)]);
        }

        $next = $line->next;
        if (null === $next) {
            return null;
        }
        $sha = $next->headSha;
        $locked = $this->forgePullRequests->findForUpdate($next->id ?? throw new \LogicException('A stored pull request has an id.'));
        if (null === $locked || null === $sha || $locked->headSha !== $sha || !SyncLine::isCandidate($locked)
            || PullRequestMergeability::Behind !== $locked->mergeability || null !== $locked->syncFromSha || null !== $locked->syncFailedReason) {
            return null;
        }

        $locked->syncFromSha = $sha;
        $locked->syncRequestedAt = $now;
        $this->em->flush();
        $this->queueTimeoutPass($projectId);

        return $locked;
    }

    /** A head that never moves fires no other pass, so this one records the timeout. It commits with the marker. */
    private function queueTimeoutPass(Uuid $projectId): void
    {
        $this->bus->dispatch(new SyncNextPullRequest($projectId), [
            new DelayStamp((SyncLine::MARKER_LIFETIME_SECONDS + self::EXPIRY_MARGIN_SECONDS) * 1000),
        ]);
    }

    /** Records the cause only while the head is the one Loupe asked to update, because a later head clears it anyway. */
    private function fail(Uuid $id, string $sha, string $cause, Uuid $projectId): void
    {
        $recorded = $this->em->wrapInTransaction(function () use ($id, $sha, $cause): bool {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null === $row || $row->headSha !== $sha) {
                return false;
            }
            $row->syncFailedReason = $cause;
            $row->syncFromSha = null;
            $row->syncRequestedAt = null;

            return true;
        });
        $this->logger->warning('board.pull_request_sync_failed', ['pullRequestId' => (string) $id, 'fromSha' => $sha, 'cause' => $cause, 'recorded' => $recorded]);

        // The failed row leaves the line, so the next pull request need not wait for the timeout pass.
        if ($recorded) {
            $this->bus->dispatch(new SyncNextPullRequest($projectId));
        }
    }
}
