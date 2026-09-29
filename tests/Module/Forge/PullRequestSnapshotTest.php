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
        self::assertTrue($pullRequest->snapshot()->equals($snapshot));
    }

    public function test_equal_snapshots_are_equal(): void
    {
        self::assertTrue($this->changed()->equals($this->changed()));
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
        ];
    }

    private function pullRequest(): ForgePullRequest
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'forge-pr');

        return new ForgePullRequest($project, 'github', 'acme/widgets', 42);
    }
}
