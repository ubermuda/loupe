<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;

/**
 * The approved pull requests of one project that wait for a sync with their
 * base, one at a time. A holder is an approved pull request that is up to date
 * or syncing, and while one exists no other pull request syncs.
 */
final readonly class SyncLine
{
    /** A sync that has not moved the head after this long counts as failed. */
    public const int MARKER_LIFETIME_SECONDS = 600;

    public const string TIMEOUT = 'timeout';

    public ?ForgePullRequest $holder;

    /** The pull request to sync now, or null while a holder exists or none is behind. */
    public ?ForgePullRequest $next;

    /** @var list<ForgePullRequest> the rows whose sync marker is older than the lifetime */
    public array $timedOut;

    /** @var array<string, PullRequestSyncView> keyed by row id; a row with nothing to show has no entry */
    public array $statuses;

    /** @param list<ForgePullRequest> $rows the open rows of one project */
    public function __construct(array $rows, \DateTimeImmutable $now)
    {
        $cutoff = $now->modify(\sprintf('-%d seconds', self::MARKER_LIFETIME_SECONDS));
        $fresh = static fn (ForgePullRequest $row): bool => null !== $row->syncFromSha && null !== $row->syncRequestedAt && $row->syncRequestedAt > $cutoff;
        $stale = static fn (ForgePullRequest $row): bool => null !== $row->syncFromSha && !$fresh($row);

        $candidates = array_values(array_filter($rows, self::isCandidate(...)));
        $this->timedOut = array_values(array_filter($rows, $stale));
        $this->holder = self::first(array_filter(
            $candidates,
            static fn (ForgePullRequest $row): bool => $fresh($row) || self::isUpToDate($row),
        ));
        $this->next = null !== $this->holder ? null : self::first(array_filter(
            $candidates,
            static fn (ForgePullRequest $row): bool => PullRequestMergeability::Behind === $row->mergeability && null === $row->syncFailedReason && !$stale($row),
        ));

        $statuses = [];
        foreach ($rows as $row) {
            $status = $this->statusFor($row, \in_array($row, $candidates, true), $fresh($row), $stale($row));
            if (null !== $status) {
                $statuses[(string) ($row->id ?? throw new \LogicException('A stored pull request has an id.'))] = $status;
            }
        }
        $this->statuses = $statuses;
    }

    public function statusOf(ForgePullRequest $row): ?PullRequestSyncView
    {
        return $this->statuses[(string) $row->id] ?? null;
    }

    private function statusFor(ForgePullRequest $row, bool $candidate, bool $fresh, bool $stale): ?PullRequestSyncView
    {
        if (!self::isInLine($row)) {
            return null;
        }
        if (null !== $row->syncFailedReason || $stale) {
            return new PullRequestSyncView(PullRequestSyncStatus::SyncFailed, $row->syncFailedReason ?? self::TIMEOUT);
        }
        if (PullRequestMergeability::Conflicting === $row->mergeability) {
            return new PullRequestSyncView(PullRequestSyncStatus::Conflicts);
        }
        if (!$candidate) {
            return new PullRequestSyncView(PullRequestSyncStatus::WaitsForApproval);
        }
        if ($fresh || (null !== $row->syncedSha && $row->syncedSha === $row->headSha && PullRequestChecks::Pending === $row->checks)) {
            return new PullRequestSyncView(PullRequestSyncStatus::SyncedChecksRunning);
        }
        if (PullRequestMergeability::Behind === $row->mergeability && $row !== $this->next) {
            return new PullRequestSyncView(PullRequestSyncStatus::WaitsTurn, blockerNumber: ($this->holder ?? $this->next)?->number);
        }

        return null;
    }

    private static function isInLine(ForgePullRequest $row): bool
    {
        return PullRequestState::Open === $row->state
            && !$row->draft
            && null !== $row->defaultBranch
            && $row->baseBranch === $row->defaultBranch;
    }

    /** Whether the approval covers the head of an open pull request on the default branch. */
    public static function isCandidate(ForgePullRequest $row): bool
    {
        return self::isInLine($row)
            && null !== $row->approvalId
            && null !== $row->coveredSha
            && $row->coveredSha === $row->headSha
            && PullRequestReview::ChangesRequested !== $row->review;
    }

    private static function isUpToDate(ForgePullRequest $row): bool
    {
        return PullRequestMergeability::Behind !== $row->mergeability
            && PullRequestMergeability::Conflicting !== $row->mergeability
            && (PullRequestChecks::Pending === $row->checks || PullRequestChecks::Passed === $row->checks);
    }

    /** @param array<ForgePullRequest> $rows */
    private static function first(array $rows): ?ForgePullRequest
    {
        usort($rows, static fn (ForgePullRequest $a, ForgePullRequest $b): int => [null === $a->approvedAt, $a->approvedAt, $a->number] <=> [null === $b->approvedAt, $b->approvedAt, $b->number]);

        return $rows[0] ?? null;
    }
}
