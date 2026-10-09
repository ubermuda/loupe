<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Service\PullRequestStateView;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class PullRequestStateViewTest extends TestCase
{
    private const string NOW = '2026-10-02 12:00:00';

    public function test_the_view_carries_the_start_times_and_the_branches(): void
    {
        $row = $this->row(new PullRequestSnapshot(baseBranch: 'main', checks: PullRequestChecks::Failed, defaultBranch: 'main'));
        $row->checksFailedSince = new \DateTimeImmutable('2026-10-02 08:00:00');
        $row->readySince = new \DateTimeImmutable('2026-10-02 07:00:00');

        $view = PullRequestStateView::of($row, null, new \DateTimeImmutable(self::NOW));

        self::assertEquals(new \DateTimeImmutable('2026-10-02 08:00:00'), $view->checksFailedSince);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 07:00:00'), $view->readySince);
        self::assertSame('main', $view->baseBranch);
        self::assertSame('main', $view->defaultBranch);
        self::assertNull($view->forgeRequestedAt);
        self::assertFalse($view->mergeInFlight);
    }

    public function test_an_approval_of_the_head_covers_it_and_a_stale_one_does_not(): void
    {
        $covered = $this->row(new PullRequestSnapshot(headSha: 'head1', review: PullRequestReview::Approved, approvalSha: 'head1', approvalId: 'review1'));
        $covered->coveredSha = 'head1';
        $stale = $this->row(new PullRequestSnapshot(headSha: 'head2', review: PullRequestReview::Approved, approvalSha: 'head1', approvalId: 'review1'));
        $stale->coveredSha = 'head1';

        self::assertTrue(PullRequestStateView::of($covered)->approvalCoversHead);
        self::assertFalse(PullRequestStateView::of($stale)->approvalCoversHead);
        self::assertFalse(PullRequestStateView::of($this->row(new PullRequestSnapshot()))->approvalCoversHead);
    }

    public function test_the_earliest_request_in_flight_is_the_forge_request_time(): void
    {
        $row = $this->row(new PullRequestSnapshot(headSha: 'head1', baseBranch: 'epic/1'));
        $row->mergeRequestedSha = 'head1';
        $row->mergeRequestedAt = new \DateTimeImmutable('2026-10-02 11:50:00');
        $row->baseChangeRequestedTo = 'main';
        $row->baseChangeRequestedAt = new \DateTimeImmutable('2026-10-02 11:40:00');

        $view = PullRequestStateView::of($row, null, new \DateTimeImmutable(self::NOW));

        self::assertEquals(new \DateTimeImmutable('2026-10-02 11:40:00'), $view->forgeRequestedAt);
        self::assertTrue($view->mergeInFlight);
    }

    public function test_a_sync_in_flight_counts_until_it_fails_or_times_out(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $fresh = $this->row(new PullRequestSnapshot());
        $fresh->syncFromSha = 'head1';
        $fresh->syncRequestedAt = new \DateTimeImmutable('2026-10-02 11:55:00');
        $timedOut = $this->row(new PullRequestSnapshot());
        $timedOut->syncFromSha = 'head1';
        $timedOut->syncRequestedAt = new \DateTimeImmutable('2026-10-02 11:00:00');
        $failed = $this->row(new PullRequestSnapshot());
        $failed->syncFromSha = 'head1';
        $failed->syncRequestedAt = new \DateTimeImmutable('2026-10-02 11:55:00');
        $failed->syncFailedReason = 'conflict';

        self::assertEquals(new \DateTimeImmutable('2026-10-02 11:55:00'), PullRequestStateView::of($fresh, null, $now)->forgeRequestedAt);
        self::assertNull(PullRequestStateView::of($timedOut, null, $now)->forgeRequestedAt);
        self::assertNull(PullRequestStateView::of($failed, null, $now)->forgeRequestedAt);
    }

    public function test_a_closed_pull_request_has_no_request_in_flight(): void
    {
        $row = $this->row(new PullRequestSnapshot(state: PullRequestState::Merged));
        $row->mergeRequestedSha = 'head1';
        $row->mergeRequestedAt = new \DateTimeImmutable('2026-10-02 11:50:00');

        $view = PullRequestStateView::of($row, null, new \DateTimeImmutable(self::NOW));

        self::assertNull($view->forgeRequestedAt);
        self::assertFalse($view->mergeInFlight);
    }

    private function row(PullRequestSnapshot $snapshot): ForgePullRequest
    {
        $row = new ForgePullRequest(new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'view'), 'github', 'acme/app', 7);
        $row->apply($snapshot);

        return $row;
    }
}
