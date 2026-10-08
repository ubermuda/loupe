<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Service\PullRequestUnreadable;
use App\Module\GitHub\Service\GitHubBranchRules;
use App\Module\GitHub\Service\GitHubPullRequestStateMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubPullRequestStateMapperTest extends TestCase
{
    private const string HEAD = '0a80537839ea354257ded862e7e407fc85bf1487';

    public function test_pull_request_604_is_pending_because_a_required_check_never_reported(): void
    {
        $snapshot = new GitHubPullRequestStateMapper()->map(self::pullRequest604(), self::rules604(), null);

        self::assertSame(PullRequestState::Open, $snapshot->state);
        self::assertFalse($snapshot->draft);
        self::assertSame(self::HEAD, $snapshot->headSha);
        self::assertSame('main', $snapshot->baseBranch);
        self::assertSame(PullRequestChecks::Pending, $snapshot->checks);
        self::assertNull($snapshot->checksSha);
        self::assertSame([], $snapshot->failedChecks);
        self::assertSame(PullRequestMergeability::Blocked, $snapshot->mergeability);
        self::assertSame(PullRequestReview::Approved, $snapshot->review);
        self::assertFalse($snapshot->readyToMerge);
    }

    public function test_every_required_check_passed_on_a_clean_pull_request_is_ready_to_merge(): void
    {
        $node = self::allPassed(self::pullRequest604());
        $node['mergeStateStatus'] = 'CLEAN';

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), 0);

        self::assertSame(PullRequestChecks::Passed, $snapshot->checks);
        self::assertSame(self::HEAD, $snapshot->checksSha);
        self::assertSame(PullRequestMergeability::Mergeable, $snapshot->mergeability);
        self::assertTrue($snapshot->readyToMerge);
    }

    public function test_failed_required_checks_are_named_in_order_and_a_failed_optional_check_is_ignored(): void
    {
        $node = self::allPassed(self::pullRequest604());
        $node = self::withContext($node, self::checkRun('phpunit', 'COMPLETED', 'FAILURE'));
        $node = self::withContext($node, self::checkRun('e2e-rest', 'COMPLETED', 'TIMED_OUT'));
        $node = self::withContext($node, self::checkRun('coverage', 'COMPLETED', 'FAILURE', required: false));

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), null);

        self::assertSame(PullRequestChecks::Failed, $snapshot->checks);
        self::assertSame(self::HEAD, $snapshot->checksSha);
        self::assertSame(['e2e-rest', 'phpunit'], $snapshot->failedChecks);
        self::assertFalse($snapshot->readyToMerge);
    }

    public function test_a_pending_required_check_outweighs_a_failed_one(): void
    {
        $node = self::withContext(self::pullRequest604(), self::checkRun('phpunit', 'COMPLETED', 'FAILURE'));

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), null);

        self::assertSame(PullRequestChecks::Pending, $snapshot->checks);
        self::assertNull($snapshot->checksSha);
        self::assertSame([], $snapshot->failedChecks);
    }

    public function test_neutral_and_skipped_conclusions_pass(): void
    {
        $node = self::allPassed(self::pullRequest604());
        $node = self::withContext($node, self::checkRun('audit', 'COMPLETED', 'NEUTRAL'));
        $node = self::withContext($node, self::checkRun('e2e', 'COMPLETED', 'SKIPPED'));

        self::assertSame(PullRequestChecks::Passed, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->checks);
    }

    public function test_the_last_entry_of_a_repeated_name_wins(): void
    {
        $node = self::allPassed(self::pullRequest604());
        $node = self::withContext($node, self::checkRun('phpunit', 'COMPLETED', 'FAILURE'));
        $node = self::withContext($node, self::checkRun('phpunit', 'COMPLETED', 'SUCCESS'));

        self::assertSame(PullRequestChecks::Passed, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->checks);
    }

    public function test_a_check_run_and_a_status_that_share_a_name_must_both_pass(): void
    {
        $node = self::withContexts(self::pullRequest604(), [self::checkRun('build', 'COMPLETED', 'FAILURE'), self::statusContext('build', 'SUCCESS')]);

        $snapshot = new GitHubPullRequestStateMapper()->map($node, new GitHubBranchRules(['build'], false), null);

        self::assertSame(PullRequestChecks::Failed, $snapshot->checks);
        self::assertSame(['build'], $snapshot->failedChecks);
    }

    public function test_without_a_ruleset_the_contexts_marked_required_decide(): void
    {
        $mapper = new GitHubPullRequestStateMapper();

        self::assertSame(PullRequestChecks::Pending, $mapper->map(self::pullRequest604(), null, null)->checks);

        $node = self::pullRequest604();
        foreach (['e2e-chromium', 'e2e-chromium-2', 'e2e-rest'] as $name) {
            $node = self::withContext($node, self::checkRun($name, 'COMPLETED', 'SUCCESS'));
        }
        $node = self::withContext($node, self::checkRun('optional', 'COMPLETED', 'FAILURE', required: false));

        self::assertSame(PullRequestChecks::Passed, $mapper->map($node, null, null)->checks);
    }

    public function test_a_check_that_classic_branch_protection_requires_counts_beside_an_empty_ruleset(): void
    {
        $node = self::withContexts(self::pullRequest604(), [self::checkRun('build', 'COMPLETED', 'FAILURE')]);

        $snapshot = new GitHubPullRequestStateMapper()->map($node, new GitHubBranchRules([], false), null);

        self::assertSame(PullRequestChecks::Failed, $snapshot->checks);
        self::assertSame(['build'], $snapshot->failedChecks);
    }

    /** @return iterable<string, array{string, PullRequestChecks}> */
    public static function emptyRequiredSets(): iterable
    {
        yield 'blocked' => ['BLOCKED', PullRequestChecks::Pending];
        yield 'clean' => ['CLEAN', PullRequestChecks::Passed];
    }

    #[DataProvider('emptyRequiredSets')]
    public function test_with_no_required_check_the_merge_state_decides(string $mergeStateStatus, PullRequestChecks $expected): void
    {
        $node = self::withContexts(self::pullRequest604(), []);
        $node['mergeStateStatus'] = $mergeStateStatus;

        $snapshot = new GitHubPullRequestStateMapper()->map($node, new GitHubBranchRules([], false), null);

        self::assertSame($expected, $snapshot->checks);
        self::assertSame(PullRequestChecks::Passed === $expected ? self::HEAD : null, $snapshot->checksSha);
    }

    public function test_status_contexts_count_like_check_runs(): void
    {
        $mapper = new GitHubPullRequestStateMapper();
        $rules = new GitHubBranchRules(['ci/legacy', 'ci/deploy'], false);
        $node = self::withContexts(self::pullRequest604(), [self::statusContext('ci/legacy', 'SUCCESS'), self::statusContext('ci/deploy', 'EXPECTED')]);

        self::assertSame(PullRequestChecks::Pending, $mapper->map($node, $rules, null)->checks);

        $node = self::withContexts(self::pullRequest604(), [self::statusContext('ci/legacy', 'ERROR'), self::statusContext('ci/deploy', 'FAILURE')]);
        $snapshot = $mapper->map($node, $rules, null);

        self::assertSame(PullRequestChecks::Failed, $snapshot->checks);
        self::assertSame(['ci/deploy', 'ci/legacy'], $snapshot->failedChecks);
    }

    public function test_a_head_without_a_rollup_waits_for_its_required_checks(): void
    {
        $node = self::pullRequest604();
        $node['commits']['nodes'][0]['commit']['statusCheckRollup'] = null;

        self::assertSame(PullRequestChecks::Pending, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->checks);
    }

    /** @return iterable<string, array{string, string, ?int, PullRequestMergeability}> */
    public static function mergeabilities(): iterable
    {
        yield 'conflicting' => ['CONFLICTING', 'BLOCKED', null, PullRequestMergeability::Conflicting];
        yield 'dirty' => ['UNKNOWN', 'DIRTY', null, PullRequestMergeability::Conflicting];
        yield 'behind by status' => ['MERGEABLE', 'BEHIND', null, PullRequestMergeability::Behind];
        yield 'behind by compare' => ['MERGEABLE', 'BLOCKED', 3, PullRequestMergeability::Behind];
        yield 'blocked' => ['MERGEABLE', 'BLOCKED', 0, PullRequestMergeability::Blocked];
        yield 'clean' => ['MERGEABLE', 'CLEAN', null, PullRequestMergeability::Mergeable];
        yield 'has hooks' => ['MERGEABLE', 'HAS_HOOKS', null, PullRequestMergeability::Mergeable];
        yield 'unstable' => ['MERGEABLE', 'UNSTABLE', null, PullRequestMergeability::Mergeable];
        yield 'not computed yet' => ['UNKNOWN', 'CLEAN', null, PullRequestMergeability::Unknown];
        yield 'unknown state' => ['MERGEABLE', 'UNKNOWN', null, PullRequestMergeability::Unknown];
    }

    #[DataProvider('mergeabilities')]
    public function test_mergeability(string $mergeable, string $mergeStateStatus, ?int $behindBy, PullRequestMergeability $expected): void
    {
        $node = self::allPassed(self::pullRequest604());
        $node['mergeable'] = $mergeable;
        $node['mergeStateStatus'] = $mergeStateStatus;

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), $behindBy);

        self::assertSame($expected, $snapshot->mergeability);
        self::assertSame(PullRequestMergeability::Mergeable === $expected, $snapshot->readyToMerge);
    }

    /** @return iterable<string, array{?string, PullRequestReview}> */
    public static function reviews(): iterable
    {
        yield 'approved' => ['APPROVED', PullRequestReview::Approved];
        yield 'changes requested' => ['CHANGES_REQUESTED', PullRequestReview::ChangesRequested];
        yield 'required' => ['REVIEW_REQUIRED', PullRequestReview::Required];
        yield 'none' => [null, PullRequestReview::None];
    }

    #[DataProvider('reviews')]
    public function test_review(?string $decision, PullRequestReview $expected): void
    {
        $node = self::pullRequest604();
        $node['reviewDecision'] = $decision;

        self::assertSame($expected, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->review);
    }

    /** @return iterable<string, array{?string, list<string>, PullRequestReview}> */
    public static function latestOpinionatedReviews(): iterable
    {
        yield 'no decision and an approval' => [null, ['APPROVED'], PullRequestReview::Approved];
        yield 'no decision, a change request and an approval' => [null, ['APPROVED', 'CHANGES_REQUESTED'], PullRequestReview::ChangesRequested];
        yield 'no decision and only comments or dismissals' => [null, ['COMMENTED', 'DISMISSED'], PullRequestReview::None];
        yield 'no decision and no reviews' => [null, [], PullRequestReview::None];
        yield 'a decision ignores the reviews' => ['REVIEW_REQUIRED', ['APPROVED'], PullRequestReview::Required];
        yield 'an approval decision ignores a change request' => ['APPROVED', ['CHANGES_REQUESTED'], PullRequestReview::Approved];
    }

    /** @param list<string> $states */
    #[DataProvider('latestOpinionatedReviews')]
    public function test_without_a_review_decision_the_latest_opinionated_reviews_decide(?string $decision, array $states, PullRequestReview $expected): void
    {
        $node = self::pullRequest604();
        $node['reviewDecision'] = $decision;
        $node['latestOpinionatedReviews'] = ['nodes' => array_map(static fn (string $state): array => ['state' => $state], $states)];

        self::assertSame($expected, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->review);
    }

    public function test_a_node_without_latest_opinionated_reviews_has_no_review(): void
    {
        $node = self::pullRequest604();
        $node['reviewDecision'] = null;
        unset($node['latestOpinionatedReviews']);

        self::assertSame(PullRequestReview::None, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->review);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function changesRequestedReviews(): iterable
    {
        $onHead = ['state' => 'CHANGES_REQUESTED', 'commit' => ['oid' => self::HEAD]];
        $onOlder = ['state' => 'CHANGES_REQUESTED', 'commit' => ['oid' => 'abc1234']];

        yield 'one on the head and one on an older commit' => [['nodes' => [$onHead, $onOlder]], self::HEAD];
        yield 'one on an older commit and one on the head' => [['nodes' => [$onOlder, $onHead]], self::HEAD];
        yield 'one on an older commit' => [['nodes' => [$onOlder]], 'abc1234'];
        yield 'the last of two on older commits' => [['nodes' => [$onOlder, ['state' => 'CHANGES_REQUESTED', 'commit' => ['oid' => 'def5678']]]], 'def5678'];
        yield 'an approval on an older commit' => [['nodes' => [['state' => 'APPROVED', 'commit' => ['oid' => 'abc1234']]]], null];
        yield 'no reviews field' => [null, null];
        yield 'empty nodes' => [['nodes' => []], null];
        yield 'empty oid' => [['nodes' => [['state' => 'CHANGES_REQUESTED', 'commit' => ['oid' => '']]]], null];
        yield 'no commit is skipped' => [['nodes' => [$onOlder, ['state' => 'CHANGES_REQUESTED', 'commit' => null]]], 'abc1234'];
    }

    #[DataProvider('changesRequestedReviews')]
    public function test_the_commit_of_an_active_change_request(mixed $reviews, ?string $expected): void
    {
        $node = self::pullRequest604();
        if (null === $reviews) {
            unset($node['latestOpinionatedReviews']);
        } else {
            $node['latestOpinionatedReviews'] = $reviews;
        }

        self::assertSame($expected, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->changesRequestedSha);
    }

    /** @return iterable<string, array{mixed, ?string, ?string, ?string}> */
    public static function approvals(): iterable
    {
        $first = ['id' => 'PRR_first', 'state' => 'APPROVED', 'submittedAt' => '2026-09-20T10:00:00Z', 'commit' => ['oid' => 'aaa1111']];
        $second = ['id' => 'PRR_second', 'state' => 'APPROVED', 'submittedAt' => '2026-09-21T09:00:00Z', 'commit' => ['oid' => 'bbb2222']];
        $onlySecond = ['2026-09-21 09:00:00', 'bbb2222', 'PRR_second'];

        yield 'no reviews field' => [null, null, null, null];
        yield 'empty nodes' => [['nodes' => []], null, null, null];
        yield 'only a change request' => [['nodes' => [['state' => 'CHANGES_REQUESTED'] + $first]], null, null, null];
        yield 'one approval' => [['nodes' => [$first]], '2026-09-20 10:00:00', 'aaa1111', 'PRR_first'];
        yield 'the oldest of two approvals' => [['nodes' => [$second, $first]], '2026-09-20 10:00:00', 'aaa1111', 'PRR_first'];
        yield 'an approval beside a change request' => [['nodes' => [['id' => 'PRR_change', 'state' => 'CHANGES_REQUESTED', 'submittedAt' => '2026-09-19T10:00:00Z', 'commit' => ['oid' => 'ccc3333']], $second]], ...$onlySecond];
        yield 'a malformed time is skipped' => [['nodes' => [['submittedAt' => 'yesterday'] + $first, $second]], ...$onlySecond];
        yield 'a missing time is skipped' => [['nodes' => [array_diff_key($first, ['submittedAt' => true]), $second]], ...$onlySecond];
        yield 'an empty oid is skipped' => [['nodes' => [['commit' => ['oid' => '']] + $first, $second]], ...$onlySecond];
        yield 'a missing commit is skipped' => [['nodes' => [['commit' => null] + $first, $second]], ...$onlySecond];
        yield 'a missing id is skipped' => [['nodes' => [array_diff_key($first, ['id' => true]), $second]], ...$onlySecond];
        yield 'an empty id is skipped' => [['nodes' => [['id' => ''] + $first, $second]], ...$onlySecond];
        yield 'an id that is not a string is skipped' => [['nodes' => [['id' => 42] + $first, $second]], ...$onlySecond];
        yield 'a node that is not an array is skipped' => [['nodes' => ['APPROVED', $second]], ...$onlySecond];
        yield 'nodes that are not a list' => [['nodes' => 'APPROVED'], null, null, null];
    }

    #[DataProvider('approvals')]
    public function test_the_oldest_approval_gives_the_approval_time_sha_and_id(mixed $reviews, ?string $approvedAt, ?string $approvalSha, ?string $approvalId): void
    {
        $node = self::pullRequest604();
        if (null === $reviews) {
            unset($node['latestOpinionatedReviews']);
        } else {
            $node['latestOpinionatedReviews'] = $reviews;
        }

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), null);

        self::assertSame($approvedAt, $snapshot->approvedAt?->format('Y-m-d H:i:s'));
        self::assertSame($approvalSha, $snapshot->approvalSha);
        self::assertSame($approvalId, $snapshot->approvalId);
    }

    public function test_an_approval_counts_whatever_the_review_decision(): void
    {
        $node = self::pullRequest604();
        $node['reviewDecision'] = 'CHANGES_REQUESTED';
        $node['latestOpinionatedReviews'] = ['nodes' => [['id' => 'PRR_first', 'state' => 'APPROVED', 'submittedAt' => '2026-09-20T12:00:00+02:00', 'commit' => ['oid' => 'aaa1111']]]];

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), null);

        self::assertSame('2026-09-20 10:00:00', $snapshot->approvedAt?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $snapshot->approvedAt->getTimezone()->getName());
        self::assertSame('aaa1111', $snapshot->approvalSha);
        self::assertSame('PRR_first', $snapshot->approvalId);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function defaultBranches(): iterable
    {
        yield 'a default branch' => [['defaultBranchRef' => ['name' => 'main']], 'main'];
        yield 'no base repository' => [null, null];
        yield 'no default branch' => [['defaultBranchRef' => null], null];
        yield 'an empty name' => [['defaultBranchRef' => ['name' => '']], null];
        yield 'a name that is not a string' => [['defaultBranchRef' => ['name' => 42]], null];
    }

    #[DataProvider('defaultBranches')]
    public function test_the_default_branch_of_the_base_repository(mixed $baseRepository, ?string $expected): void
    {
        $node = self::pullRequest604();
        if (null === $baseRepository) {
            unset($node['baseRepository']);
        } else {
            $node['baseRepository'] = $baseRepository;
        }

        self::assertSame($expected, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->defaultBranch);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function headBranches(): iterable
    {
        yield 'a head branch' => ['feature/x', 'feature/x'];
        yield 'no head branch' => [null, null];
        yield 'an empty name' => ['', null];
        yield 'a name that is not a string' => [42, null];
    }

    #[DataProvider('headBranches')]
    public function test_the_head_branch(mixed $headRefName, ?string $expected): void
    {
        $node = self::pullRequest604();
        if (null === $headRefName) {
            unset($node['headRefName']);
        } else {
            $node['headRefName'] = $headRefName;
        }

        self::assertSame($expected, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->headBranch);
    }

    /** @return iterable<string, array{mixed, ?string, ?string}> */
    public static function authors(): iterable
    {
        yield 'a user' => [['login' => 'ubermuda', 'databaseId' => 1234], '1234', 'ubermuda'];
        yield 'a bot has a login and no id' => [['login' => 'dependabot'], null, 'dependabot'];
        yield 'a deleted account' => [null, null, null];
        yield 'an empty login' => [['login' => '', 'databaseId' => 0], null, null];
        yield 'values of the wrong type' => [['login' => 7, 'databaseId' => '12'], null, null];
    }

    #[DataProvider('authors')]
    public function test_the_author(mixed $author, ?string $expectedId, ?string $expectedLogin): void
    {
        $node = self::pullRequest604();
        $node['author'] = $author;

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), null);

        self::assertSame($expectedId, $snapshot->authorId);
        self::assertSame($expectedLogin, $snapshot->authorLogin);
    }

    /** @return iterable<string, array{mixed, list<string>}> */
    public static function headParents(): iterable
    {
        yield 'one parent' => [['nodes' => [['oid' => 'aaa1111']]], ['aaa1111']];
        yield 'a merge commit' => [['nodes' => [['oid' => 'aaa1111'], ['oid' => 'bbb2222']]], ['aaa1111', 'bbb2222']];
        yield 'no parents field' => [null, []];
        yield 'nodes that are not a list' => [['nodes' => 'aaa1111'], []];
        yield 'a malformed parent is skipped' => [['nodes' => [['oid' => ''], 'bbb2222', ['oid' => 42], ['oid' => 'ccc3333']]], ['ccc3333']];
    }

    /** @param list<string> $expected */
    #[DataProvider('headParents')]
    public function test_the_parents_of_the_head_commit(mixed $parents, array $expected): void
    {
        $node = self::pullRequest604();
        if (null === $parents) {
            unset($node['commits']['nodes'][0]['commit']['parents']);
        } else {
            $node['commits']['nodes'][0]['commit']['parents'] = $parents;
        }

        self::assertSame($expected, new GitHubPullRequestStateMapper()->map($node, self::rules604(), null)->headParents);
    }

    public function test_a_draft_is_never_ready_to_merge(): void
    {
        $node = self::allPassed(self::pullRequest604());
        $node['mergeStateStatus'] = 'CLEAN';
        $node['isDraft'] = true;

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), null);

        self::assertTrue($snapshot->draft);
        self::assertFalse($snapshot->readyToMerge);
    }

    /** @return iterable<string, array{string, PullRequestState}> */
    public static function closedStates(): iterable
    {
        yield 'merged' => ['MERGED', PullRequestState::Merged];
        yield 'closed' => ['CLOSED', PullRequestState::Closed];
    }

    #[DataProvider('closedStates')]
    public function test_a_closed_pull_request_is_never_ready_to_merge(string $state, PullRequestState $expected): void
    {
        $node = self::allPassed(self::pullRequest604());
        $node['mergeStateStatus'] = 'CLEAN';
        $node['state'] = $state;

        $snapshot = new GitHubPullRequestStateMapper()->map($node, self::rules604(), null);

        self::assertSame($expected, $snapshot->state);
        self::assertFalse($snapshot->readyToMerge);
    }

    public function test_an_open_pull_request_has_an_open_time_and_no_merge_time(): void
    {
        $node = self::pullRequest604();
        $node['createdAt'] = '2026-09-20T08:00:00Z';
        $node['mergedAt'] = null;

        $snapshot = new GitHubPullRequestStateMapper()->map($node, null, null);

        self::assertEquals(new \DateTimeImmutable('2026-09-20 08:00:00', new \DateTimeZone('UTC')), $snapshot->openedAt);
        self::assertSame('UTC', $snapshot->openedAt?->getTimezone()->getName());
        self::assertNull($snapshot->mergedAt);
    }

    public function test_a_merged_pull_request_has_an_open_and_a_merge_time_in_utc(): void
    {
        $node = self::pullRequest604();
        $node['state'] = 'MERGED';
        $node['createdAt'] = '2026-09-20T08:00:00Z';
        $node['mergedAt'] = '2026-09-21T11:30:00+02:00';

        $snapshot = new GitHubPullRequestStateMapper()->map($node, null, null);

        self::assertEquals(new \DateTimeImmutable('2026-09-20 08:00:00', new \DateTimeZone('UTC')), $snapshot->openedAt);
        self::assertSame('2026-09-21 09:30:00', $snapshot->mergedAt?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $snapshot->mergedAt->getTimezone()->getName());
    }

    public function test_a_closed_unmerged_pull_request_has_no_merge_time(): void
    {
        $node = self::pullRequest604();
        $node['state'] = 'CLOSED';
        $node['createdAt'] = '2026-09-20T08:00:00Z';
        $node['mergedAt'] = null;

        $snapshot = new GitHubPullRequestStateMapper()->map($node, null, null);

        self::assertNotNull($snapshot->openedAt);
        self::assertNull($snapshot->mergedAt);
    }

    public function test_an_absent_or_malformed_time_is_null(): void
    {
        $node = self::pullRequest604();
        unset($node['createdAt']);
        $node['mergedAt'] = 'yesterday';

        $snapshot = new GitHubPullRequestStateMapper()->map($node, null, null);

        self::assertNull($snapshot->openedAt);
        self::assertNull($snapshot->mergedAt);
    }

    public function test_a_node_without_its_core_fields_is_unreadable(): void
    {
        $node = self::pullRequest604();
        unset($node['headRefOid']);

        try {
            new GitHubPullRequestStateMapper()->map($node, null, null);
            self::fail('Expected PullRequestUnreadable.');
        } catch (PullRequestUnreadable $e) {
            self::assertSame('malformed_body', $e->reason);
        }
    }

    public function test_the_ruleset_of_the_repository_reads_every_required_context_and_strictness(): void
    {
        $rules = self::rules604();

        self::assertCount(13, $rules->requiredChecks);
        self::assertContains('e2e', $rules->requiredChecks);
        self::assertTrue($rules->strict);
    }

    public function test_several_status_check_rules_merge_and_any_strict_rule_makes_the_branch_strict(): void
    {
        $rules = GitHubBranchRules::fromRules([
            ['type' => 'required_status_checks', 'parameters' => ['strict_required_status_checks_policy' => false, 'required_status_checks' => [['context' => 'lint']]]],
            ['type' => 'deletion'],
            ['type' => 'required_status_checks', 'parameters' => ['strict_required_status_checks_policy' => true, 'required_status_checks' => [['context' => 'lint'], ['context' => 'test'], ['integration_id' => 1]]]],
        ]);

        self::assertSame(['lint', 'test'], $rules->requiredChecks);
        self::assertTrue($rules->strict);
        self::assertEquals(new GitHubBranchRules([], false), GitHubBranchRules::fromRules([]));
    }

    public function test_a_ruleset_answer_that_is_not_a_list_is_refused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        GitHubBranchRules::fromRules(['message' => 'Not Found']);
    }

    /** @return array<string, mixed> */
    private static function pullRequest604(): array
    {
        $answer = self::fixture('graphql');
        $node = $answer['data']['repository']['pullRequest'] ?? null;
        self::assertIsArray($node);

        return $node;
    }

    private static function rules604(): GitHubBranchRules
    {
        return GitHubBranchRules::fromRules(self::fixture('rules'));
    }

    /** @return array<mixed> */
    private static function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__.'/fixtures/pull-request-604-'.$name.'.json');
        self::assertIsString($json);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function allPassed(array $node): array
    {
        foreach (self::rules604()->requiredChecks as $name) {
            $node = self::withContext($node, self::checkRun($name, 'COMPLETED', 'SUCCESS'));
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function withContext(array $node, array $context): array
    {
        $node['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['nodes'][] = $context;

        return $node;
    }

    /**
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $contexts
     *
     * @return array<string, mixed>
     */
    private static function withContexts(array $node, array $contexts): array
    {
        $node['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['nodes'] = $contexts;

        return $node;
    }

    /** @return array<string, mixed> */
    private static function checkRun(string $name, string $status, ?string $conclusion, bool $required = true): array
    {
        return ['__typename' => 'CheckRun', 'name' => $name, 'status' => $status, 'conclusion' => $conclusion, 'isRequired' => $required];
    }

    /** @return array<string, mixed> */
    private static function statusContext(string $context, string $state): array
    {
        return ['__typename' => 'StatusContext', 'context' => $context, 'state' => $state, 'isRequired' => true];
    }
}
