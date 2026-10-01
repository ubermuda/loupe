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
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
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

        $pullRequest = $this->em->wrapInTransaction(fn (): ?ForgePullRequest => $this->pick($project, $command->projectId));
        if (null === $pullRequest) {
            return;
        }
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');
        $sha = $pullRequest->syncFromSha ?? throw new \LogicException('The pick carries its marker.');

        $updater = $this->updaters->for($pullRequest->forge);
        if (null === $updater) {
            $this->fail($id, $sha, 'no_updater');

            return;
        }

        try {
            $updater->update($pullRequest, $sha);
        } catch (PullRequestSyncFailed $e) {
            if ($e->permanent) {
                $this->fail($id, $sha, $e->cause);

                return;
            }

            $this->em->wrapInTransaction(function () use ($id, $sha): void {
                $row = $this->forgePullRequests->findForUpdate($id);
                if (null !== $row && $row->syncFromSha === $sha) {
                    $row->syncFromSha = null;
                    $row->syncRequestedAt = null;
                }
            });
            $this->logger->warning('board.pull_request_sync_retried', ['pullRequestId' => (string) $id, 'cause' => $e->cause, 'retryAfterSeconds' => $e->retryAfterSeconds]);

            if (null === $e->retryAfterSeconds) {
                throw $e;
            }

            // forceRetry false keeps the retry budget of the transport, and only the delay changes.
            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, retryDelay: min($e->retryAfterSeconds, self::MAX_RETRY_DELAY_SECONDS) * 1000, forceRetry: false);
        }

        // GitHub accepts the update before the merge commit exists, so a later read records the sync on the cards.
        $this->logger->info('board.pull_request_sync_requested', [
            'pullRequestId' => (string) $id,
            'projectId' => (string) $command->projectId,
            'forge' => $pullRequest->forge,
            'repository' => $pullRequest->repository,
            'pullRequestNumber' => $pullRequest->number,
            'fromSha' => $sha,
        ]);
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

        // A head that never moves fires no other pass, so this one records the timeout. It commits with the marker.
        $this->bus->dispatch(new SyncNextPullRequest($projectId), [
            new DelayStamp((SyncLine::MARKER_LIFETIME_SECONDS + self::EXPIRY_MARGIN_SECONDS) * 1000),
        ]);

        return $locked;
    }

    /** Records the cause only while the head is the one Loupe asked to update, because a later head clears it anyway. */
    private function fail(Uuid $id, string $sha, string $cause): void
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
    }
}
