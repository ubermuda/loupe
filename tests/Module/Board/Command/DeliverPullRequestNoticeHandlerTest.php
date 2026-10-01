<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\DeliverPullRequestNoticeCommand;
use App\Module\Board\Command\DeliverPullRequestNoticeHandler;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Board\Service\StaleApprovalNoticeBody;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Forge\Service\PullRequestCommentFailed;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\FakePullRequestCommenter;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

final class DeliverPullRequestNoticeHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private const string HEAD = 'bbbbbbb2222222222222222222222222222222bb';

    private EntityManagerInterface $em;
    private MockClock $clock;
    private FakePullRequestCommenter $commenter;
    private Project $project;
    private ForgePullRequest $pullRequest;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->clock = new MockClock('2026-10-01 12:00:00');
        $this->commenter = new FakePullRequestCommenter();

        $this->project = $this->makeProject('deliver-notice');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->pullRequest->headSha = $this->pullRequest->uncoveredSha = self::HEAD;
        $this->pullRequest->approvalId = 'review1';
        $this->pullRequest->approvalSha = $this->pullRequest->coveredSha = 'approved1';
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_it_posts_the_stale_approval_notice_and_marks_it_posted(): void
    {
        $notice = $this->pending();

        $this->handle($notice);

        self::assertCount(1, $this->commenter->comments);
        self::assertSame($this->pullRequest, $this->commenter->comments[0][0]);
        self::assertSame(
            '<!-- loupe-notice: stale-approval:'.self::HEAD." -->\n\n"
            .'Not merged: commit `bbbbbbb` came after your approval. Approve the new head to merge.',
            $this->commenter->comments[0][1],
        );
        self::assertSame([], $this->commenter->lookups);
        $notice = $this->reload($notice);
        self::assertSame(PullRequestCommentState::Posted, $notice->state);
        self::assertEquals($this->clock->now(), $notice->postedAt);
        self::assertSame(1, $notice->attempts);
        self::assertNull($notice->cause);
    }

    public function test_a_retry_that_finds_its_marker_marks_the_notice_posted_without_posting_again(): void
    {
        $notice = $this->pending();
        $notice->attempts = 1;
        $notice->cause = 'api_failed_transport';
        $this->em->flush();
        $marker = '<!-- loupe-notice: stale-approval:'.self::HEAD.' -->';
        $this->commenter->existing = [$marker."\n\nAn earlier body"];

        $this->handle($notice);

        self::assertSame([], $this->commenter->comments);
        self::assertCount(1, $this->commenter->lookups);
        self::assertSame($marker, $this->commenter->lookups[0][0]);
        self::assertEquals($notice->createdAt->modify('-1 hour'), $this->commenter->lookups[0][1]);
        $notice = $this->reload($notice);
        self::assertSame(PullRequestCommentState::Posted, $notice->state);
        self::assertNull($notice->cause);
        self::assertSame(2, $notice->attempts);
    }

    public function test_a_retry_that_finds_no_marker_posts(): void
    {
        $notice = $this->pending();
        $notice->attempts = 1;
        $this->em->flush();
        $this->commenter->existing = ['<!-- loupe-notice: stale-approval:ccccccc -->'];

        $this->handle($notice);

        self::assertCount(1, $this->commenter->lookups);
        self::assertCount(1, $this->commenter->comments);
        self::assertSame(PullRequestCommentState::Posted, $this->reload($notice)->state);
    }

    public function test_a_permanent_failure_marks_the_notice_failed(): void
    {
        $this->commenter->failure = new PullRequestCommentFailed('permission', permanent: true);
        $notice = $this->pending();

        $this->handle($notice);

        $notice = $this->reload($notice);
        self::assertSame(PullRequestCommentState::Failed, $notice->state);
        self::assertSame('permission', $notice->cause);
        self::assertEquals($this->clock->now(), $notice->failedAt);
        self::assertSame(1, $notice->attempts);
    }

    public function test_a_transient_failure_counts_the_attempt_and_rethrows(): void
    {
        $failure = new PullRequestCommentFailed('api_failed_http_status_502', permanent: false);
        $this->commenter->failure = $failure;
        $notice = $this->pending();

        try {
            $this->handle($notice);
            self::fail('Expected the transient failure to propagate.');
        } catch (PullRequestCommentFailed $e) {
            self::assertSame($failure, $e);
        }

        $notice = $this->reload($notice);
        self::assertSame(PullRequestCommentState::Pending, $notice->state);
        self::assertSame(1, $notice->attempts);
        self::assertSame('api_failed_http_status_502', $notice->cause);
        self::assertNull($notice->failedAt);
    }

    public function test_a_transient_failure_with_a_delay_asks_messenger_to_wait(): void
    {
        $failure = new PullRequestCommentFailed('api_failed_rate_limited', permanent: false, retryAfterSeconds: 90);
        $this->commenter->failure = $failure;

        try {
            $this->handle($this->pending());
            self::fail('Expected the transient failure to propagate.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(90_000, $e->getRetryDelay());
            self::assertFalse($e->forceRetry());
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function test_a_failed_notice_retried_by_hand_is_posted(): void
    {
        $notice = $this->pending();
        $notice->state = PullRequestCommentState::Failed;
        $notice->failedAt = $this->clock->now();
        $notice->attempts = 4;
        $this->em->flush();

        $this->handle($notice);

        self::assertCount(1, $this->commenter->comments);
        $notice = $this->reload($notice);
        self::assertSame(PullRequestCommentState::Posted, $notice->state);
        self::assertNull($notice->failedAt);
    }

    public function test_a_notice_whose_pull_request_is_gone_is_marked_failed(): void
    {
        $notice = $this->pending(Uuid::v7());

        $this->handle($notice);

        self::assertSame([], $this->commenter->comments);
        $notice = $this->reload($notice);
        self::assertSame(PullRequestCommentState::Failed, $notice->state);
        self::assertSame('unknown_pull_request', $notice->cause);
    }

    /** @return iterable<string, array{\Closure(ForgePullRequest): void}> */
    public static function outdatedPullRequests(): iterable
    {
        yield 'a new head' => [static function (ForgePullRequest $pullRequest): void { $pullRequest->headSha = 'ccccccc'; }];
        yield 'a new approval of the head' => [static function (ForgePullRequest $pullRequest): void { $pullRequest->coveredSha = self::HEAD; }];
        yield 'a merge' => [static function (ForgePullRequest $pullRequest): void { $pullRequest->state = PullRequestState::Merged; }];
        yield 'a new approval whose coverage is unknown' => [static function (ForgePullRequest $pullRequest): void { $pullRequest->uncoveredSha = null; }];
    }

    public function test_a_retry_that_finds_its_marker_after_the_head_moved_marks_the_notice_posted(): void
    {
        $notice = $this->pending();
        $notice->attempts = 1;
        $this->pullRequest->headSha = 'ccccccc';
        $this->em->flush();
        $this->commenter->existing = ['<!-- loupe-notice: stale-approval:'.self::HEAD." -->\n\nAn earlier body"];

        $this->handle($notice);

        self::assertSame([], $this->commenter->comments);
        self::assertSame(PullRequestCommentState::Posted, $this->reload($notice)->state);
    }

    #[DataProvider('outdatedPullRequests')]
    public function test_a_notice_that_the_pull_request_outdated_before_delivery_is_not_posted(\Closure $change): void
    {
        $notice = $this->pending();
        $change($this->pullRequest);
        $this->em->flush();

        $this->handle($notice);

        self::assertSame([], $this->commenter->comments);
        $notice = $this->reload($notice);
        self::assertSame(PullRequestCommentState::Failed, $notice->state);
        self::assertSame('outdated', $notice->cause);
    }

    public function test_a_posted_notice_is_not_posted_again(): void
    {
        $notice = $this->pending();
        $notice->state = PullRequestCommentState::Posted;
        $this->em->flush();

        $this->handle($notice);

        self::assertSame([], $this->commenter->comments);
    }

    private function pending(?Uuid $forgePullRequestId = null): PullRequestNotice
    {
        $notice = new PullRequestNotice(
            project: $this->project,
            forgePullRequestId: $forgePullRequestId ?? $this->pullRequest->id ?? throw new \LogicException('A flushed pull request has an id.'),
            noticeKey: StaleApprovalNoticeBody::key(self::HEAD),
            createdAt: $this->clock->now(),
        );
        $this->em->persist($notice);
        $this->em->flush();

        return $notice;
    }

    private function handle(PullRequestNotice $notice): void
    {
        $notices = self::getContainer()->get(PullRequestNoticeRepository::class);
        self::assertInstanceOf(PullRequestNoticeRepository::class, $notices);
        $pullRequests = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $pullRequests);
        $body = self::getContainer()->get(StaleApprovalNoticeBody::class);
        self::assertInstanceOf(StaleApprovalNoticeBody::class, $body);

        $handler = new DeliverPullRequestNoticeHandler(
            pullRequestNotices: $notices,
            forgePullRequests: $pullRequests,
            commenters: new PullRequestCommenters([$this->commenter]),
            body: $body,
            em: $this->em,
            clock: $this->clock,
            logger: new NullLogger(),
        );

        $handler(new DeliverPullRequestNoticeCommand($notice->id ?? throw new \LogicException('A flushed notice has an id.')));
    }

    private function reload(PullRequestNotice $notice): PullRequestNotice
    {
        $this->em->refresh($notice);

        return $notice;
    }
}
