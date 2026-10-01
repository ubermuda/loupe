<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Service\PullRequestSyncStatus;
use App\Module\Board\Service\PullRequestSyncView;
use App\Module\Board\Service\SyncLine;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SyncLineTest extends TestCase
{
    private const string NOW = '2026-09-30 12:00:00';

    private Project $project;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'Widgets');
        $this->now = new \DateTimeImmutable(self::NOW);
    }

    public function test_the_oldest_approved_behind_pull_request_is_next(): void
    {
        $newer = $this->candidate(1, approvedAt: '-1 hour');
        $older = $this->candidate(2, approvedAt: '-2 hours');

        $line = new SyncLine([$newer, $older], $this->now);

        self::assertNull($line->holder);
        self::assertSame($older, $line->next);
        self::assertNull($line->statusOf($older));
        $this->assertStatus($line, $newer, PullRequestSyncStatus::WaitsTurn, blockerNumber: 2);
    }

    public function test_the_lower_number_breaks_a_tie(): void
    {
        $nine = $this->candidate(9, approvedAt: '-1 hour');
        $three = $this->candidate(3, approvedAt: '-1 hour');

        self::assertSame($three, new SyncLine([$nine, $three], $this->now)->next);
    }

    public function test_an_up_to_date_candidate_with_running_or_passed_checks_holds_the_line(): void
    {
        $holder = $this->candidate(4, approvedAt: '-1 hour', mergeability: PullRequestMergeability::Mergeable, checks: PullRequestChecks::Passed);
        $holder->nextRefreshAt = $this->now->modify('+1 minute');
        $behind = $this->candidate(5, approvedAt: '-2 hours');

        $line = new SyncLine([$holder, $behind], $this->now);

        self::assertSame($holder, $line->holder);
        self::assertNull($line->next);
        self::assertNull($line->holderRereadAt);
        self::assertNull($line->statusOf($holder));
        $this->assertStatus($line, $behind, PullRequestSyncStatus::WaitsTurn, blockerNumber: 4);
    }

    public function test_an_unknown_mergeability_with_a_read_still_scheduled_holds_the_line(): void
    {
        $holder = $this->candidate(4, mergeability: PullRequestMergeability::Unknown, checks: PullRequestChecks::Pending);
        $holder->nextRefreshAt = $this->now->modify('+1 minute');

        $line = new SyncLine([$holder, $this->candidate(5)], $this->now);

        self::assertSame($holder, $line->holder);
        self::assertNull($line->next);
        self::assertEquals($this->now->modify('+1 minute'), $line->holderRereadAt);
    }

    public function test_an_unknown_mergeability_under_a_fresh_marker_waits_for_no_reread(): void
    {
        $holder = $this->candidate(4, mergeability: PullRequestMergeability::Unknown, checks: PullRequestChecks::Pending);
        $holder->nextRefreshAt = $this->now->modify('+1 minute');
        $this->mark($holder, '-1 minute');

        self::assertNull(new SyncLine([$holder], $this->now)->holderRereadAt);
    }

    public function test_an_unknown_mergeability_that_the_reads_gave_up_on_does_not_hold_the_line(): void
    {
        $unknown = $this->candidate(4, mergeability: PullRequestMergeability::Unknown, checks: PullRequestChecks::Pending);
        $behind = $this->candidate(5);

        $line = new SyncLine([$unknown, $behind], $this->now);

        self::assertNull($line->holder);
        self::assertSame($behind, $line->next);
    }

    public function test_an_unknown_mergeability_on_the_head_the_app_synced_holds_the_line(): void
    {
        $holder = $this->candidate(4, mergeability: PullRequestMergeability::Unknown, checks: PullRequestChecks::Pending);
        $holder->syncedSha = $holder->headSha;
        $holder->nextRefreshAt = $this->now->modify('+1 minute');

        $line = new SyncLine([$holder, $this->candidate(5)], $this->now);

        self::assertSame($holder, $line->holder);
        self::assertNull($line->next);
        self::assertNull($line->holderRereadAt);
    }

    public function test_failed_checks_do_not_hold_the_line(): void
    {
        $failing = $this->candidate(4, mergeability: PullRequestMergeability::Mergeable, checks: PullRequestChecks::Failed);
        $behind = $this->candidate(5);

        $line = new SyncLine([$failing, $behind], $this->now);

        self::assertNull($line->holder);
        self::assertSame($behind, $line->next);
        self::assertNull($line->statusOf($failing));
    }

    public function test_a_conflicting_candidate_does_not_hold_the_line(): void
    {
        $conflicting = $this->candidate(4, mergeability: PullRequestMergeability::Conflicting, checks: PullRequestChecks::Passed);
        $behind = $this->candidate(5);

        $line = new SyncLine([$conflicting, $behind], $this->now);

        self::assertNull($line->holder);
        self::assertSame($behind, $line->next);
        $this->assertStatus($line, $conflicting, PullRequestSyncStatus::Conflicts);
    }

    public function test_the_oldest_of_several_holders_is_the_holder(): void
    {
        $newer = $this->candidate(4, approvedAt: '-1 hour', mergeability: PullRequestMergeability::Mergeable);
        $older = $this->candidate(6, approvedAt: '-3 hours', mergeability: PullRequestMergeability::Blocked);
        $behind = $this->candidate(5, approvedAt: '-5 hours');

        $line = new SyncLine([$newer, $older, $behind], $this->now);

        self::assertSame($older, $line->holder);
        $this->assertStatus($line, $behind, PullRequestSyncStatus::WaitsTurn, blockerNumber: 6);
    }

    public function test_a_fresh_marker_holds_the_line_and_reads_as_synced(): void
    {
        $syncing = $this->candidate(4, approvedAt: '-1 hour');
        $this->mark($syncing, '-9 minutes');
        $behind = $this->candidate(5, approvedAt: '-2 hours');

        $line = new SyncLine([$syncing, $behind], $this->now);

        self::assertSame($syncing, $line->holder);
        self::assertNull($line->next);
        self::assertSame([], $line->timedOut);
        $this->assertStatus($line, $syncing, PullRequestSyncStatus::SyncedChecksRunning);
        $this->assertStatus($line, $behind, PullRequestSyncStatus::WaitsTurn, blockerNumber: 4);
    }

    public function test_a_stale_marker_times_out_and_frees_the_line(): void
    {
        $stale = $this->candidate(4, approvedAt: '-3 hours');
        $this->mark($stale, '-10 minutes');
        $behind = $this->candidate(5, approvedAt: '-2 hours');

        $line = new SyncLine([$stale, $behind], $this->now);

        self::assertNull($line->holder);
        self::assertSame($behind, $line->next);
        self::assertSame([$stale], $line->timedOut);
        $this->assertStatus($line, $stale, PullRequestSyncStatus::SyncFailed, reason: SyncLine::TIMEOUT);
    }

    public function test_a_failed_sync_is_never_picked_again_and_shows_its_reason(): void
    {
        $failed = $this->candidate(4, approvedAt: '-3 hours');
        $failed->syncFailedReason = 'refused';
        $behind = $this->candidate(5, approvedAt: '-2 hours');

        $line = new SyncLine([$failed, $behind], $this->now);

        self::assertSame($behind, $line->next);
        $this->assertStatus($line, $failed, PullRequestSyncStatus::SyncFailed, reason: 'refused');
    }

    public function test_a_failure_wins_over_a_conflict(): void
    {
        $row = $this->candidate(4, mergeability: PullRequestMergeability::Conflicting);
        $row->syncFailedReason = 'refused';

        $this->assertStatus(new SyncLine([$row], $this->now), $row, PullRequestSyncStatus::SyncFailed, reason: 'refused');
    }

    public function test_a_conflict_shows_even_without_an_approval(): void
    {
        $row = $this->candidate(4, mergeability: PullRequestMergeability::Conflicting);
        $row->approvalId = null;

        $this->assertStatus(new SyncLine([$row], $this->now), $row, PullRequestSyncStatus::Conflicts);
    }

    /** @return iterable<string, array{\Closure(ForgePullRequest): void}> */
    public static function notCandidates(): iterable
    {
        yield 'no approval' => [static function (ForgePullRequest $row): void { $row->approvalId = null; }];
        yield 'no covered head' => [static function (ForgePullRequest $row): void { $row->coveredSha = null; }];
        yield 'the approval covers an older head' => [static function (ForgePullRequest $row): void { $row->coveredSha = 'old0000'; }];
        yield 'changes requested' => [static function (ForgePullRequest $row): void { $row->review = PullRequestReview::ChangesRequested; }];
    }

    /** @param \Closure(ForgePullRequest): void $change */
    #[DataProvider('notCandidates')]
    public function test_a_pull_request_the_approval_does_not_cover_waits_for_approval(\Closure $change): void
    {
        $row = $this->candidate(4);
        $change($row);

        $line = new SyncLine([$row], $this->now);

        self::assertNull($line->next);
        self::assertNull($line->holder);
        $this->assertStatus($line, $row, PullRequestSyncStatus::WaitsForApproval);
    }

    public function test_an_uncovered_up_to_date_pull_request_does_not_hold_the_line(): void
    {
        $unapproved = $this->candidate(4, mergeability: PullRequestMergeability::Mergeable);
        $unapproved->approvalId = null;
        $behind = $this->candidate(5);

        self::assertSame($behind, new SyncLine([$unapproved, $behind], $this->now)->next);
    }

    /** @return iterable<string, array{\Closure(ForgePullRequest): void}> */
    public static function outOfLine(): iterable
    {
        yield 'a draft' => [static function (ForgePullRequest $row): void { $row->draft = true; }];
        yield 'a closed one' => [static function (ForgePullRequest $row): void { $row->state = PullRequestState::Closed; }];
        yield 'a merged one' => [static function (ForgePullRequest $row): void { $row->state = PullRequestState::Merged; }];
        yield 'another base' => [static function (ForgePullRequest $row): void { $row->baseBranch = 'release'; }];
        yield 'an unknown default branch' => [static function (ForgePullRequest $row): void { $row->defaultBranch = null; }];
    }

    /** @param \Closure(ForgePullRequest): void $change */
    #[DataProvider('outOfLine')]
    public function test_a_pull_request_outside_the_line_has_no_status_and_is_never_picked(\Closure $change): void
    {
        $row = $this->candidate(4, approvedAt: '-3 hours');
        $change($row);
        $other = $this->candidate(5, approvedAt: '-1 hour');

        $line = new SyncLine([$row, $other], $this->now);

        self::assertNull($line->statusOf($row));
        self::assertSame($other, $line->next);
    }

    public function test_a_stacked_pull_request_does_not_hold_the_line(): void
    {
        $stacked = $this->candidate(4, mergeability: PullRequestMergeability::Mergeable);
        $stacked->baseBranch = 'feature';
        $behind = $this->candidate(5);

        self::assertSame($behind, new SyncLine([$stacked, $behind], $this->now)->next);
    }

    public function test_a_head_the_app_synced_reads_as_synced_while_its_checks_run(): void
    {
        $row = $this->candidate(4, mergeability: PullRequestMergeability::Mergeable, checks: PullRequestChecks::Pending);
        $row->syncedSha = $row->headSha;

        $this->assertStatus(new SyncLine([$row], $this->now), $row, PullRequestSyncStatus::SyncedChecksRunning);
    }

    public function test_a_head_the_app_synced_shows_nothing_once_its_checks_conclude(): void
    {
        $row = $this->candidate(4, mergeability: PullRequestMergeability::Mergeable, checks: PullRequestChecks::Passed);
        $row->syncedSha = $row->headSha;

        self::assertNull(new SyncLine([$row], $this->now)->statusOf($row));
    }

    public function test_an_up_to_date_candidate_shows_nothing(): void
    {
        $row = $this->candidate(4, mergeability: PullRequestMergeability::Mergeable, checks: PullRequestChecks::Pending);

        self::assertNull(new SyncLine([$row], $this->now)->statusOf($row));
    }

    public function test_an_empty_project_has_no_line(): void
    {
        $line = new SyncLine([], $this->now);

        self::assertNull($line->holder);
        self::assertNull($line->next);
        self::assertSame([], $line->timedOut);
        self::assertSame([], $line->statuses);
    }

    public function test_the_statuses_are_keyed_by_row_id(): void
    {
        $row = $this->candidate(4, mergeability: PullRequestMergeability::Conflicting);

        $line = new SyncLine([$row], $this->now);

        self::assertSame([(string) $row->id], array_keys($line->statuses));
    }

    private function assertStatus(SyncLine $line, ForgePullRequest $row, PullRequestSyncStatus $status, ?string $reason = null, ?int $blockerNumber = null): void
    {
        self::assertEquals(new PullRequestSyncView($status, $reason, $blockerNumber), $line->statusOf($row));
    }

    private function mark(ForgePullRequest $row, string $requestedAt): void
    {
        $row->syncFromSha = $row->headSha;
        $row->syncRequestedAt = $this->now->modify($requestedAt);
    }

    private function candidate(
        int $number,
        string $approvedAt = '-1 hour',
        PullRequestMergeability $mergeability = PullRequestMergeability::Behind,
        PullRequestChecks $checks = PullRequestChecks::Passed,
    ): ForgePullRequest {
        $row = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', $number);
        new \ReflectionProperty(ForgePullRequest::class, 'id')->setValue($row, Uuid::v7());
        $row->headSha = \sprintf('head%03d', $number);
        $row->baseBranch = 'main';
        $row->defaultBranch = 'main';
        $row->approvalId = 'review-'.$number;
        $row->approvalSha = $row->headSha;
        $row->coveredSha = $row->headSha;
        $row->approvedAt = $this->now->modify($approvedAt);
        $row->review = PullRequestReview::Approved;
        $row->mergeability = $mergeability;
        $row->checks = $checks;

        return $row;
    }
}
