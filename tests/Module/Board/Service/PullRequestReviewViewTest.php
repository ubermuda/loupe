<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Service\PullRequestReviewView;
use App\Module\Board\Service\PullRequestStateView;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PullRequestReviewViewTest extends TestCase
{
    public function test_an_approval_that_does_not_cover_the_head_reads_outdated(): void
    {
        $row = $this->row(PullRequestReview::Approved, head: 'pushed1', approvalId: 'review1');

        self::assertSame(PullRequestReviewView::ApprovalOutdated, PullRequestReviewView::of($row));
        self::assertSame(PullRequestReviewView::ApprovalOutdated, PullRequestStateView::of($row)->review);
        self::assertSame('approval-outdated', PullRequestReviewView::ApprovalOutdated->value);
    }

    public function test_an_approval_that_covers_the_head_reads_approved(): void
    {
        $row = $this->row(PullRequestReview::Approved, head: 'approved1', approvalId: 'review1');

        self::assertSame(PullRequestReviewView::Approved, PullRequestReviewView::of($row));
    }

    public function test_a_merged_or_closed_pull_request_reads_approved_after_a_later_push(): void
    {
        foreach ([PullRequestState::Merged, PullRequestState::Closed] as $state) {
            $row = $this->row(PullRequestReview::Approved, head: 'pushed1', approvalId: 'review1');
            $row->state = $state;

            self::assertSame(PullRequestReviewView::Approved, PullRequestReviewView::of($row));
        }
    }

    /** @return iterable<string, array{PullRequestReview, PullRequestReviewView}> */
    public static function otherReviews(): iterable
    {
        yield 'changes requested' => [PullRequestReview::ChangesRequested, PullRequestReviewView::ChangesRequested];
        yield 'required' => [PullRequestReview::Required, PullRequestReviewView::Required];
        yield 'none' => [PullRequestReview::None, PullRequestReviewView::None];
    }

    #[DataProvider('otherReviews')]
    public function test_a_review_that_is_not_an_approval_keeps_its_value_with_a_stale_approval(PullRequestReview $review, PullRequestReviewView $expected): void
    {
        $row = $this->row($review, head: 'pushed1', approvalId: 'review1');

        self::assertSame($expected, PullRequestReviewView::of($row));
        self::assertSame($review->value, $expected->value);
    }

    private function row(PullRequestReview $review, string $head, ?string $approvalId): ForgePullRequest
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'review-view');
        $row = new ForgePullRequest($project, 'github', 'acme/widgets', 42);
        $row->apply(new PullRequestSnapshot(headSha: 'approved1', review: $review, approvalSha: 'approved1', approvalId: $approvalId));
        $row->apply(new PullRequestSnapshot(headSha: $head, review: $review, approvalSha: 'approved1', approvalId: $approvalId));

        return $row;
    }
}
