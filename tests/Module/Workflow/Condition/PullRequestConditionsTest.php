<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\Condition;
use App\Module\Workflow\Condition\PullRequestApprovalCoversHead;
use App\Module\Workflow\Condition\PullRequestBaseIsMergeTarget;
use App\Module\Workflow\Condition\PullRequestBehind;
use App\Module\Workflow\Condition\PullRequestChangesRequested;
use App\Module\Workflow\Condition\PullRequestChecksFailed;
use App\Module\Workflow\Condition\PullRequestChecksPassed;
use App\Module\Workflow\Condition\PullRequestConflicting;
use App\Module\Workflow\Condition\PullRequestDraft;
use App\Module\Workflow\Condition\PullRequestOpen;
use App\Module\Workflow\Condition\PullRequestParentMerged;
use App\Module\Workflow\Condition\PullRequestsAllClosedUnmerged;
use App\Module\Workflow\Condition\PullRequestsAllFinishedOneMerged;
use App\Module\Workflow\Condition\PullRequestStacked;
use App\Module\Workflow\Fact\ChecksState;
use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestFacts;
use App\Module\Workflow\Fact\PullRequestState;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PullRequestConditionsTest extends TestCase
{
    private const string NOW = '2026-10-01 12:00:00';

    /** @param array<string, mixed> $params */
    #[DataProvider('cases')]
    public function test_it_evaluates_the_facts(Condition $condition, array $params, Facts $facts, bool $expected): void
    {
        self::assertSame($expected, $condition->evaluate($facts, $params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, Facts, bool}> */
    public static function cases(): iterable
    {
        yield 'open, open' => [new PullRequestOpen(), [], self::current(state: PullRequestState::Open), true];
        yield 'open, closed' => [new PullRequestOpen(), [], self::current(state: PullRequestState::Closed), false];
        yield 'open, merged' => [new PullRequestOpen(), [], self::current(state: PullRequestState::Merged), false];

        yield 'draft, open draft' => [new PullRequestDraft(), [], self::current(draft: true), true];
        yield 'draft, ready' => [new PullRequestDraft(), [], self::current(draft: false), false];
        yield 'draft, closed draft' => [new PullRequestDraft(), [], self::current(state: PullRequestState::Closed, draft: true), false];

        yield 'checks passed, passed' => [new PullRequestChecksPassed(), [], self::current(checks: ChecksState::Passed), true];
        yield 'checks passed, pending' => [new PullRequestChecksPassed(), [], self::current(checks: ChecksState::Pending), false];
        yield 'checks passed, none' => [new PullRequestChecksPassed(), [], self::current(checks: ChecksState::None), false];

        yield 'checks failed, failed' => [new PullRequestChecksFailed(), [], self::current(checks: ChecksState::Failed), true];
        yield 'checks failed, passed' => [new PullRequestChecksFailed(), [], self::current(checks: ChecksState::Passed), false];

        yield 'conflicting' => [new PullRequestConflicting(), [], self::current(conflicting: true), true];
        yield 'not conflicting' => [new PullRequestConflicting(), [], self::current(conflicting: false), false];

        yield 'behind' => [new PullRequestBehind(), [], self::current(behind: true), true];
        yield 'not behind' => [new PullRequestBehind(), [], self::current(behind: false), false];

        yield 'approvals, enough' => [new PullRequestApprovalCoversHead(), ['min' => 2], self::current(approvalsCoveringHead: 2), true];
        yield 'approvals, too few' => [new PullRequestApprovalCoversHead(), ['min' => 2], self::current(approvalsCoveringHead: 1), false];

        yield 'changes requested' => [new PullRequestChangesRequested(), [], self::current(changesRequested: true), true];
        yield 'no changes requested' => [new PullRequestChangesRequested(), [], self::current(changesRequested: false), false];

        yield 'base is merge target' => [new PullRequestBaseIsMergeTarget(), [], self::current(baseIsMergeTarget: true), true];
        yield 'base is not merge target' => [new PullRequestBaseIsMergeTarget(), [], self::current(baseIsMergeTarget: false), false];

        yield 'stacked' => [new PullRequestStacked(), [], self::current(stacked: true), true];
        yield 'not stacked' => [new PullRequestStacked(), [], self::current(stacked: false), false];

        yield 'parent merged' => [new PullRequestParentMerged(), [], self::current(stacked: true, parentMerged: true), true];
        yield 'parent open' => [new PullRequestParentMerged(), [], self::current(stacked: true, parentMerged: false), false];
        yield 'parent merged, not stacked' => [new PullRequestParentMerged(), [], self::current(stacked: false, parentMerged: true), false];

        $open = FactsMother::pullRequest(state: PullRequestState::Open);
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        $closedAnHourAgo = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));

        yield 'all finished, one merged' => [new PullRequestsAllFinishedOneMerged(), [], self::all([$closedAnHourAgo, $merged]), true];
        yield 'all finished, one still open' => [new PullRequestsAllFinishedOneMerged(), [], self::all([$merged, $open]), false];
        yield 'all finished, none merged' => [new PullRequestsAllFinishedOneMerged(), [], self::all([$closedAnHourAgo]), false];
        yield 'all finished, none linked' => [new PullRequestsAllFinishedOneMerged(), [], self::all([]), false];

        yield 'all closed unmerged, 10 minutes ago' => [new PullRequestsAllClosedUnmerged(), ['minutes' => 10], self::all([$closedAnHourAgo, self::closedMinutesAgo(10)]), true];
        yield 'all closed unmerged, 9 minutes ago' => [new PullRequestsAllClosedUnmerged(), ['minutes' => 10], self::all([$closedAnHourAgo, self::closedMinutesAgo(9)]), false];
        yield 'all closed unmerged, one merged' => [new PullRequestsAllClosedUnmerged(), ['minutes' => 10], self::all([$closedAnHourAgo, $merged]), false];
        yield 'all closed unmerged, one open' => [new PullRequestsAllClosedUnmerged(), ['minutes' => 10], self::all([$closedAnHourAgo, $open]), false];
        yield 'all closed unmerged, close time unknown' => [new PullRequestsAllClosedUnmerged(), ['minutes' => 10], self::all([FactsMother::pullRequest(state: PullRequestState::Closed)]), false];
        yield 'all closed unmerged, none linked' => [new PullRequestsAllClosedUnmerged(), ['minutes' => 10], self::all([]), false];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('singlePullRequestConditions')]
    public function test_a_single_pull_request_condition_is_false_with_no_current_pull_request(Condition $condition, array $params): void
    {
        $facts = FactsMother::facts(pullRequest: null, pullRequests: [FactsMother::pullRequest(
            draft: true,
            checks: ChecksState::Passed,
            conflicting: true,
            behind: true,
            approvalsCoveringHead: 5,
            changesRequested: true,
            baseIsMergeTarget: true,
            stacked: true,
            parentMerged: true,
        )]);

        self::assertFalse($condition->evaluate($facts, $params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>}> */
    public static function singlePullRequestConditions(): iterable
    {
        yield 'pr.open' => [new PullRequestOpen(), []];
        yield 'pr.draft' => [new PullRequestDraft(), []];
        yield 'pr.checks_passed' => [new PullRequestChecksPassed(), []];
        yield 'pr.checks_failed' => [new PullRequestChecksFailed(), []];
        yield 'pr.conflicting' => [new PullRequestConflicting(), []];
        yield 'pr.behind' => [new PullRequestBehind(), []];
        yield 'pr.approval_covers_head' => [new PullRequestApprovalCoversHead(), ['min' => 1]];
        yield 'pr.changes_requested' => [new PullRequestChangesRequested(), []];
        yield 'pr.base_is_merge_target' => [new PullRequestBaseIsMergeTarget(), []];
        yield 'pr.stacked' => [new PullRequestStacked(), []];
        yield 'pr.parent_merged' => [new PullRequestParentMerged(), []];
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedParameters
     * @param list<FactKey>         $expectedReads
     */
    #[DataProvider('waiting')]
    public function test_it_says_what_it_waits_for_and_what_it_reads(Condition $condition, array $params, array $expectedParameters, array $expectedReads): void
    {
        $message = $condition->waitingFor($params);

        self::assertSame('workflow.waiting.'.str_replace('.', '_', $condition::key()), $message->getMessage());
        self::assertSame($expectedParameters, $message->getParameters());
        self::assertSame($expectedReads, $condition->reads($params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, array<string, string>, list<FactKey>}> */
    public static function waiting(): iterable
    {
        $one = [FactKey::PullRequest];
        yield 'pr.open' => [new PullRequestOpen(), [], [], $one];
        yield 'pr.draft' => [new PullRequestDraft(), [], [], $one];
        yield 'pr.checks_passed' => [new PullRequestChecksPassed(), [], [], $one];
        yield 'pr.checks_failed' => [new PullRequestChecksFailed(), [], [], $one];
        yield 'pr.conflicting' => [new PullRequestConflicting(), [], [], $one];
        yield 'pr.behind' => [new PullRequestBehind(), [], [], $one];
        yield 'pr.approval_covers_head' => [new PullRequestApprovalCoversHead(), ['min' => 2], ['%min%' => '2'], $one];
        yield 'pr.changes_requested' => [new PullRequestChangesRequested(), [], [], $one];
        yield 'pr.base_is_merge_target' => [new PullRequestBaseIsMergeTarget(), [], [], $one];
        yield 'pr.stacked' => [new PullRequestStacked(), [], [], $one];
        yield 'pr.parent_merged' => [new PullRequestParentMerged(), [], [], $one];
        yield 'pr.all_finished_one_merged' => [new PullRequestsAllFinishedOneMerged(), [], [], [FactKey::PullRequests]];
        yield 'pr.all_closed_unmerged' => [new PullRequestsAllClosedUnmerged(), ['minutes' => 10], ['%minutes%' => '10'], [FactKey::PullRequests]];
    }

    private static function current(
        PullRequestState $state = PullRequestState::Open,
        bool $draft = false,
        ChecksState $checks = ChecksState::None,
        bool $conflicting = false,
        bool $behind = false,
        int $approvalsCoveringHead = 0,
        bool $changesRequested = false,
        bool $baseIsMergeTarget = true,
        bool $stacked = false,
        bool $parentMerged = false,
    ): Facts {
        $pullRequest = FactsMother::pullRequest(
            state: $state,
            draft: $draft,
            checks: $checks,
            conflicting: $conflicting,
            behind: $behind,
            approvalsCoveringHead: $approvalsCoveringHead,
            changesRequested: $changesRequested,
            baseIsMergeTarget: $baseIsMergeTarget,
            stacked: $stacked,
            parentMerged: $parentMerged,
        );

        return FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest], now: new \DateTimeImmutable(self::NOW));
    }

    /** @param list<PullRequestFacts> $pullRequests */
    private static function all(array $pullRequests): Facts
    {
        return FactsMother::facts(pullRequest: $pullRequests[0] ?? null, pullRequests: $pullRequests, now: new \DateTimeImmutable(self::NOW));
    }

    private static function closedMinutesAgo(int $minutes): PullRequestFacts
    {
        return FactsMother::pullRequest(
            state: PullRequestState::Closed,
            closedAt: new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d minutes', $minutes)),
        );
    }
}
