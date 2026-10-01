<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Service\ApprovalCoverage;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PullRequestSnapshotTest extends TestCase
{
    public function test_a_new_row_holds_the_default_snapshot(): void
    {
        $pullRequest = $this->pullRequest();

        self::assertSame(PullRequestState::Open, $pullRequest->state);
        self::assertFalse($pullRequest->draft);
        self::assertSame(PullRequestChecks::Pending, $pullRequest->checks);
        self::assertSame(PullRequestMergeability::Unknown, $pullRequest->mergeability);
        self::assertSame(PullRequestReview::None, $pullRequest->review);
        self::assertFalse($pullRequest->readyToMerge);
        self::assertSame([], $pullRequest->failedChecks);
        self::assertNull($pullRequest->refreshedAt);
        self::assertSame(0, $pullRequest->refreshAttempts);
        self::assertNull($pullRequest->changesRequestedSha);
        self::assertNull($pullRequest->openedAt);
        self::assertNull($pullRequest->mergedAt);
        self::assertNull($pullRequest->approvedAt);
        self::assertNull($pullRequest->approvalSha);
        self::assertNull($pullRequest->approvalId);
        self::assertNull($pullRequest->coveredSha);
        self::assertNull($pullRequest->defaultBranch);
        self::assertSame([], $pullRequest->headParents);
        self::assertNull($pullRequest->syncFromSha);
        self::assertNull($pullRequest->syncRequestedAt);
        self::assertNull($pullRequest->syncFailedReason);
        self::assertNull($pullRequest->syncedSha);
        self::assertTrue($pullRequest->snapshot()->equals(new PullRequestSnapshot()));
    }

    public function test_apply_copies_every_field_and_snapshot_reads_them_back(): void
    {
        $pullRequest = $this->pullRequest();
        $snapshot = $this->changed();

        $pullRequest->apply($snapshot);

        self::assertSame(PullRequestState::Merged, $pullRequest->state);
        self::assertTrue($pullRequest->draft);
        self::assertSame('1000', $pullRequest->headSha);
        self::assertSame('main', $pullRequest->baseBranch);
        self::assertSame(PullRequestChecks::Failed, $pullRequest->checks);
        self::assertSame('abc123', $pullRequest->checksSha);
        self::assertSame(['lint', 'phpunit'], $pullRequest->failedChecks);
        self::assertSame(PullRequestMergeability::Conflicting, $pullRequest->mergeability);
        self::assertSame(PullRequestReview::ChangesRequested, $pullRequest->review);
        self::assertTrue($pullRequest->readyToMerge);
        self::assertSame('1000', $pullRequest->changesRequestedSha);
        self::assertEquals(new \DateTimeImmutable('2026-09-20 08:00:00'), $pullRequest->openedAt);
        self::assertEquals(new \DateTimeImmutable('2026-09-21 09:30:00'), $pullRequest->mergedAt);
        self::assertTrue($pullRequest->snapshot()->equals($snapshot));
        self::assertEquals(new \DateTimeImmutable('2026-09-20 08:00:00'), $pullRequest->snapshot()->openedAt);
        self::assertEquals(new \DateTimeImmutable('2026-09-21 09:30:00'), $pullRequest->snapshot()->mergedAt);
        self::assertEquals(new \DateTimeImmutable('2026-09-20 10:00:00'), $pullRequest->approvedAt);
        self::assertSame('approved1', $pullRequest->approvalSha);
        self::assertSame('main', $pullRequest->defaultBranch);
        self::assertSame(['parent1', 'parent2'], $pullRequest->headParents);
        self::assertEquals(new \DateTimeImmutable('2026-09-20 10:00:00'), $pullRequest->snapshot()->approvedAt);
        self::assertSame('approved1', $pullRequest->snapshot()->approvalSha);
        self::assertSame('main', $pullRequest->snapshot()->defaultBranch);
        self::assertSame(['parent1', 'parent2'], $pullRequest->snapshot()->headParents);
        self::assertSame('PRR_review1', $pullRequest->approvalId);
        self::assertSame('PRR_review1', $pullRequest->snapshot()->approvalId);
        self::assertSame('approved1', $pullRequest->snapshot()->coveredSha);
    }

    public function test_the_first_approval_sets_the_covered_sha(): void
    {
        $pullRequest = $this->pullRequest();

        $pullRequest->apply($this->approved('review1', 'approved1'));

        self::assertSame('approved1', $pullRequest->coveredSha);
    }

    public function test_the_same_approval_with_a_moved_sha_keeps_the_covered_sha(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1'));

        $pullRequest->apply($this->approved('review1', 'mergecommit'));

        self::assertSame('mergecommit', $pullRequest->approvalSha);
        self::assertSame('approved1', $pullRequest->coveredSha);
    }

    public function test_a_new_approval_in_the_same_second_resets_the_covered_sha(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1'));

        $pullRequest->apply($this->approved('review2', 'approved2'));

        self::assertSame('approved2', $pullRequest->coveredSha);
    }

    public function test_a_removed_approval_clears_the_covered_sha(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1'));

        $pullRequest->apply(new PullRequestSnapshot());

        self::assertNull($pullRequest->approvalId);
        self::assertNull($pullRequest->coveredSha);
    }

    public function test_a_read_with_no_approval_leaves_a_covered_sha_alone(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->coveredSha = 'synced1';

        $pullRequest->apply(new PullRequestSnapshot(headSha: 'synced1'));

        self::assertSame('synced1', $pullRequest->coveredSha);
    }

    public function test_the_head_of_an_app_sync_moves_the_covered_sha(): void
    {
        $pullRequest = $this->syncRequested();

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'synced1', parents: ['approved1', 'base1']));

        self::assertSame('synced1', $pullRequest->coveredSha);
        self::assertSame('synced1', $pullRequest->syncedSha);
        self::assertNull($pullRequest->syncFromSha);
        self::assertNull($pullRequest->syncRequestedAt);
        self::assertNull($pullRequest->syncFailedReason);
    }

    public function test_a_head_whose_first_parent_is_not_the_sync_start_keeps_the_covered_sha(): void
    {
        $pullRequest = $this->syncRequested();

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1', parents: ['base1', 'approved1']));

        self::assertSame('approved1', $pullRequest->coveredSha);
        self::assertNull($pullRequest->syncedSha);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_a_commit_pushed_on_the_sync_start_is_not_the_sync(): void
    {
        $pullRequest = $this->syncRequested();

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1', parents: ['approved1']));

        self::assertSame('approved1', $pullRequest->coveredSha);
        self::assertNull($pullRequest->syncedSha);
        self::assertNull($pullRequest->syncFromSha);
        self::assertNull($pullRequest->syncRequestedAt);
    }

    public function test_a_commit_with_more_than_two_parents_is_not_the_sync(): void
    {
        $pullRequest = $this->syncRequested();

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'octopus1', parents: ['approved1', 'base1', 'other1']));

        self::assertSame('approved1', $pullRequest->coveredSha);
        self::assertNull($pullRequest->syncedSha);
    }

    public function test_a_pushed_head_forgets_the_synced_head(): void
    {
        $pullRequest = $this->syncRequested();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'synced1', parents: ['approved1', 'base1']));

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1', parents: ['synced1']));

        self::assertNull($pullRequest->syncedSha);
        self::assertSame('synced1', $pullRequest->coveredSha);
    }

    public function test_a_read_of_the_same_head_keeps_the_synced_head(): void
    {
        $pullRequest = $this->syncRequested();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'synced1', parents: ['approved1', 'base1']));

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'synced1', parents: ['approved1', 'base1']));

        self::assertSame('synced1', $pullRequest->syncedSha);
    }

    public function test_a_sync_start_that_the_approval_does_not_cover_keeps_the_covered_sha(): void
    {
        $pullRequest = $this->syncRequested();
        $pullRequest->syncFromSha = 'other1';

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'synced1', parents: ['other1', 'base1']));

        self::assertSame('approved1', $pullRequest->coveredSha);
        self::assertNull($pullRequest->syncedSha);
    }

    public function test_a_new_approval_of_another_head_keeps_the_coverage_with_the_approval(): void
    {
        $pullRequest = $this->syncRequested();

        $pullRequest->apply($this->approved('review2', 'approved2', head: 'synced1', parents: ['approved1', 'base1']));

        self::assertSame('approved2', $pullRequest->coveredSha);
        self::assertNull($pullRequest->syncedSha);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_a_new_approval_of_the_sync_start_moves_the_coverage_to_the_synced_head(): void
    {
        $pullRequest = $this->syncRequested();

        $pullRequest->apply($this->approved('review2', 'approved1', head: 'synced1', parents: ['approved1', 'base1']));

        self::assertSame('synced1', $pullRequest->coveredSha);
        self::assertSame('synced1', $pullRequest->syncedSha);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_a_new_approval_keeps_the_marker_and_clears_the_failure(): void
    {
        $pullRequest = $this->syncRequested();
        $pullRequest->syncFailedReason = 'refused';

        $pullRequest->apply($this->approved('review2', 'approved1', head: 'approved1'));

        self::assertSame('approved1', $pullRequest->syncFromSha);
        self::assertEquals(new \DateTimeImmutable('2026-09-20 11:00:00'), $pullRequest->syncRequestedAt);
        self::assertNull($pullRequest->syncFailedReason);
    }

    public function test_an_approval_while_the_sync_runs_and_then_the_synced_head_moves_the_coverage(): void
    {
        $pullRequest = $this->syncRequested();
        $pullRequest->apply($this->approved('review2', 'approved1', head: 'approved1'));

        $pullRequest->apply($this->approved('review2', 'approved1', head: 'synced1', parents: ['approved1', 'base1']));

        self::assertSame('synced1', $pullRequest->coveredSha);
        self::assertSame('synced1', $pullRequest->syncedSha);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_a_new_head_clears_the_sync_failure(): void
    {
        $pullRequest = $this->syncRequested();
        $pullRequest->syncFailedReason = 'refused';

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1', parents: ['base1']));

        self::assertNull($pullRequest->syncFailedReason);
        self::assertNull($pullRequest->syncFromSha);
        self::assertNull($pullRequest->syncRequestedAt);
    }

    public function test_the_same_head_and_approval_keep_the_sync_marker(): void
    {
        $pullRequest = $this->syncRequested();
        $pullRequest->syncFailedReason = 'refused';

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'approved1'));

        self::assertSame('approved1', $pullRequest->syncFromSha);
        self::assertEquals(new \DateTimeImmutable('2026-09-20 11:00:00'), $pullRequest->syncRequestedAt);
        self::assertSame('refused', $pullRequest->syncFailedReason);
    }

    public function test_a_covered_head_moves_the_covered_sha_to_the_head(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'merged1'));

        $pullRequest->recordCoverage(ApprovalCoverage::Covered);

        self::assertSame('merged1', $pullRequest->coveredSha);
        self::assertNull($pullRequest->uncoveredSha);
    }

    public function test_a_head_that_is_not_covered_is_remembered(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1'));

        $pullRequest->recordCoverage(ApprovalCoverage::NotCovered);

        self::assertSame('approved1', $pullRequest->coveredSha);
        self::assertSame('pushed1', $pullRequest->uncoveredSha);
    }

    public function test_an_unknown_coverage_stores_nothing(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1'));

        $pullRequest->recordCoverage(ApprovalCoverage::Unknown);

        self::assertSame('approved1', $pullRequest->coveredSha);
        self::assertNull($pullRequest->uncoveredSha);
    }

    public function test_a_new_approval_forgets_the_head_that_was_not_covered(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1'));
        $pullRequest->recordCoverage(ApprovalCoverage::NotCovered);

        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1'));
        self::assertSame('pushed1', $pullRequest->uncoveredSha);

        $pullRequest->apply($this->approved('review2', 'pushed1', head: 'pushed1'));
        self::assertNull($pullRequest->uncoveredSha);
        self::assertSame('pushed1', $pullRequest->coveredSha);
    }

    public function test_an_approval_that_does_not_cover_the_head_is_stale_and_holds_the_merge(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'pushed1'));

        $pullRequest->settleReadyToMerge(true);

        self::assertTrue($pullRequest->approvalIsStale());
        self::assertFalse($pullRequest->readyToMerge);
    }

    public function test_an_approval_that_covers_the_head_keeps_the_forge_readiness(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'approved1'));

        $pullRequest->settleReadyToMerge(true);
        self::assertFalse($pullRequest->approvalIsStale());
        self::assertTrue($pullRequest->readyToMerge);

        $pullRequest->settleReadyToMerge(false);
        self::assertFalse($pullRequest->readyToMerge);
    }

    public function test_a_pull_request_with_no_approval_keeps_the_forge_readiness(): void
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply(new PullRequestSnapshot(headSha: 'pushed1'));

        $pullRequest->settleReadyToMerge(true);

        self::assertFalse($pullRequest->approvalIsStale());
        self::assertTrue($pullRequest->readyToMerge);
    }

    public function test_equal_snapshots_are_equal(): void
    {
        self::assertTrue($this->changed()->equals($this->changed()));
    }

    public function test_the_open_and_merge_times_do_not_break_equality(): void
    {
        $arguments = [...self::changedArguments(), 'openedAt' => null, 'mergedAt' => null];

        self::assertTrue($this->changed()->equals(new PullRequestSnapshot(...$arguments)));
    }

    public function test_the_approval_and_branch_facts_do_not_break_equality(): void
    {
        $arguments = [...self::changedArguments(), 'approvedAt' => null, 'approvalSha' => null, 'defaultBranch' => null, 'headParents' => [], 'approvalId' => null, 'coveredSha' => 'other1'];

        self::assertTrue($this->changed()->equals(new PullRequestSnapshot(...$arguments)));
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('oneFieldChanges')]
    public function test_a_change_to_one_field_breaks_equality(array $change): void
    {
        $arguments = [...self::changedArguments(), ...$change];

        self::assertFalse($this->changed()->equals(new PullRequestSnapshot(...$arguments)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function oneFieldChanges(): iterable
    {
        yield 'state' => [['state' => PullRequestState::Closed]];
        yield 'draft' => [['draft' => false]];
        yield 'head' => [['headSha' => 'def456']];
        yield 'numeric-looking head' => [['headSha' => '1e3']];
        yield 'base' => [['baseBranch' => 'develop']];
        yield 'checks' => [['checks' => PullRequestChecks::Passed]];
        yield 'checks sha' => [['checksSha' => null]];
        yield 'failed checks' => [['failedChecks' => ['lint']]];
        yield 'mergeability' => [['mergeability' => PullRequestMergeability::Mergeable]];
        yield 'review' => [['review' => PullRequestReview::Approved]];
        yield 'ready' => [['readyToMerge' => false]];
        yield 'changes requested sha' => [['changesRequestedSha' => 'fed9876']];
        yield 'numeric-looking changes requested sha' => [['changesRequestedSha' => '1e3']];
    }

    #[DataProvider('checkReads')]
    public function test_checks_conclude_on_a_new_result_or_a_new_sha(PullRequestSnapshot $previous, PullRequestSnapshot $current, bool $expected): void
    {
        self::assertSame($expected, $current->checksConcludedSince($previous));
    }

    /** @return iterable<string, array{PullRequestSnapshot, PullRequestSnapshot, bool}> */
    public static function checkReads(): iterable
    {
        $passed = new PullRequestSnapshot(checks: PullRequestChecks::Passed, checksSha: 'aaa1111');
        $failed = new PullRequestSnapshot(checks: PullRequestChecks::Failed, checksSha: 'aaa1111');

        yield 'pending to passed' => [new PullRequestSnapshot(), $passed, true];
        yield 'pending to failed' => [new PullRequestSnapshot(), $failed, true];
        yield 'failed to passed on one sha' => [$failed, $passed, true];
        yield 'passed again on one sha' => [$passed, $passed, false];
        yield 'passed again on a new sha' => [$passed, new PullRequestSnapshot(checks: PullRequestChecks::Passed, checksSha: 'bbb2222'), true];
        yield 'still pending' => [new PullRequestSnapshot(), new PullRequestSnapshot(checksSha: 'bbb2222'), false];
    }

    private function changed(): PullRequestSnapshot
    {
        return new PullRequestSnapshot(...self::changedArguments());
    }

    /** @return array<string, mixed> */
    private static function changedArguments(): array
    {
        return [
            'state' => PullRequestState::Merged,
            'draft' => true,
            'headSha' => '1000',
            'baseBranch' => 'main',
            'checks' => PullRequestChecks::Failed,
            'checksSha' => 'abc123',
            'failedChecks' => ['lint', 'phpunit'],
            'mergeability' => PullRequestMergeability::Conflicting,
            'review' => PullRequestReview::ChangesRequested,
            'readyToMerge' => true,
            'changesRequestedSha' => '1000',
            'openedAt' => new \DateTimeImmutable('2026-09-20 08:00:00'),
            'mergedAt' => new \DateTimeImmutable('2026-09-21 09:30:00'),
            'approvedAt' => new \DateTimeImmutable('2026-09-20 10:00:00'),
            'approvalSha' => 'approved1',
            'defaultBranch' => 'main',
            'headParents' => ['parent1', 'parent2'],
            'approvalId' => 'PRR_review1',
        ];
    }

    /** @param list<string> $parents */
    private function approved(string $id, string $sha, ?string $head = null, array $parents = []): PullRequestSnapshot
    {
        return new PullRequestSnapshot(headSha: $head, review: PullRequestReview::Approved, approvedAt: new \DateTimeImmutable('2026-09-20 10:00:00'), approvalSha: $sha, headParents: $parents, approvalId: $id);
    }

    /** An approved pull request on its approved head, with an app sync asked from that head. */
    private function syncRequested(): ForgePullRequest
    {
        $pullRequest = $this->pullRequest();
        $pullRequest->apply($this->approved('review1', 'approved1', head: 'approved1'));
        $pullRequest->syncFromSha = 'approved1';
        $pullRequest->syncRequestedAt = new \DateTimeImmutable('2026-09-20 11:00:00');

        return $pullRequest;
    }

    private function pullRequest(): ForgePullRequest
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'forge-pr');

        return new ForgePullRequest($project, 'github', 'acme/widgets', 42);
    }
}
