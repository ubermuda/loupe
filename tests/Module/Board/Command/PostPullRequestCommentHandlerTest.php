<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\PostPullRequestCommentCommand;
use App\Module\Board\Command\PostPullRequestCommentHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Board\Service\FixRunCommentBody;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Forge\Service\PullRequestCommentFailed;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RuleBudgets;
use App\Tests\Module\Board\FakePullRequestCommenter;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PostPullRequestCommentHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private MockClock $clock;
    private FakePullRequestCommenter $commenter;
    private Project $project;
    private Card $card;
    private ForgePullRequest $pullRequest;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->clock = new MockClock('2026-09-29 12:00:00');
        $this->commenter = new FakePullRequestCommenter();

        $this->project = $this->makeProject('post-comment');
        $this->card = new Card($this->project, $this->column($this->project, 'backlog'), 'Ship it', '', 1);
        $this->em->persist($this->card);
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->pullRequest->failedChecks = ['phpunit', 'e2e'];
        $this->pullRequest->checksSha = 'abc1234';
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_it_posts_the_comment_and_marks_it_posted(): void
    {
        $comment = $this->pending();

        $this->handle($comment);

        self::assertCount(1, $this->commenter->comments);
        self::assertSame($this->pullRequest, $this->commenter->comments[0][0]);
        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Posted, $comment->state);
        self::assertEquals($this->clock->now(), $comment->postedAt);
        self::assertSame(1, $comment->attempts);
        self::assertNull($comment->cause);
    }

    public function test_the_body_names_the_reason_the_checks_the_stored_round_and_links_the_card(): void
    {
        $comment = $this->pending(fixRound: 2, runId: $this->fixRun('fix-in-review'));
        $this->handle($comment, $this->budgets('fix-in-review', 3));

        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        $cardUrl = $urls->generate('app_board_card', ['projectId' => (string) $this->project->id, 'cardId' => (string) $this->card->id], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertStringStartsWith('http', $cardUrl);

        self::assertSame(
            "Loupe queued a fix run for this pull request.\n\n"
            ."**Reason:** checks failed\n"
            ."**Failed checks:** `phpunit`, `e2e`\n"
            ."**Round:** 2 of 3\n\n"
            .'[Open the card and its runs in Loupe]('.$cardUrl.')'
            ."\n\n<!-- loupe-fix-run:".$comment->runId->toRfc4122().' -->',
            $this->commenter->comments[0][1],
        );
    }

    public function test_the_checks_of_another_head_are_not_named(): void
    {
        $this->pullRequest->checksSha = 'fedcba9';
        $this->em->flush();

        $this->handle($this->pending());

        $body = $this->commenter->comments[0][1];
        self::assertStringContainsString('**Reason:** checks failed', $body);
        self::assertStringNotContainsString('**Failed checks:**', $body);
    }

    public function test_the_first_try_posts_without_a_lookup(): void
    {
        $this->handle($this->pending());

        self::assertSame([], $this->commenter->lookups);
        self::assertCount(1, $this->commenter->comments);
    }

    public function test_a_retry_that_finds_its_marker_marks_the_comment_posted_without_posting_again(): void
    {
        $comment = $this->pending();
        $comment->attempts = 1;
        $comment->cause = 'api_failed_transport';
        $this->em->flush();
        $marker = '<!-- loupe-fix-run:'.$comment->runId->toRfc4122().' -->';
        $this->commenter->existing = ["An earlier body\n\n".$marker];

        $this->handle($comment);

        self::assertSame([], $this->commenter->comments);
        self::assertCount(1, $this->commenter->lookups);
        self::assertSame($marker, $this->commenter->lookups[0][0]);
        self::assertEquals($comment->createdAt->modify('-1 hour'), $this->commenter->lookups[0][1]);
        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Posted, $comment->state);
        self::assertEquals($this->clock->now(), $comment->postedAt);
        self::assertNull($comment->cause);
        self::assertSame(2, $comment->attempts);
    }

    public function test_a_retry_that_finds_no_marker_posts(): void
    {
        $comment = $this->pending();
        $comment->attempts = 1;
        $this->em->flush();
        $this->commenter->existing = ['<!-- loupe-fix-run:'.Uuid::v7()->toRfc4122().' -->'];

        $this->handle($comment);

        self::assertCount(1, $this->commenter->lookups);
        self::assertCount(1, $this->commenter->comments);
        self::assertSame(PullRequestCommentState::Posted, $this->reload($comment)->state);
    }

    public function test_a_permanent_lookup_failure_marks_the_comment_failed(): void
    {
        $comment = $this->pending();
        $comment->attempts = 1;
        $this->em->flush();
        $this->commenter->lookupFailure = new PullRequestCommentFailed('permission', permanent: true);

        $this->handle($comment);

        self::assertSame([], $this->commenter->comments);
        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Failed, $comment->state);
        self::assertSame('permission', $comment->cause);
    }

    public function test_a_transient_lookup_failure_counts_the_attempt_and_rethrows(): void
    {
        $comment = $this->pending();
        $comment->attempts = 1;
        $this->em->flush();
        $failure = new PullRequestCommentFailed('api_failed_http_status_502', permanent: false);
        $this->commenter->lookupFailure = $failure;

        try {
            $this->handle($comment);
            self::fail('Expected the transient failure to propagate.');
        } catch (PullRequestCommentFailed $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $this->commenter->comments);
        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
        self::assertSame(2, $comment->attempts);
        self::assertSame('api_failed_http_status_502', $comment->cause);
    }

    public function test_a_round_whose_rule_has_no_limit_or_whose_run_is_gone_omits_the_round(): void
    {
        $this->handle($this->pending(fixRound: 2, runId: $this->fixRun('fix-in-review')), $this->budgets('other-rule', 3));
        $this->handle($this->pending(fixRound: 2), $this->budgets('fix-in-review', 3));

        self::assertCount(2, $this->commenter->comments);
        self::assertStringNotContainsString('**Round:**', $this->commenter->comments[0][1]);
        self::assertStringNotContainsString('**Round:**', $this->commenter->comments[1][1]);
    }

    public function test_a_comment_without_a_round_omits_it_and_a_conflict_omits_the_checks(): void
    {
        $this->handle($this->pending(reason: 'conflict'));

        $body = $this->commenter->comments[0][1];
        self::assertStringContainsString('**Reason:** merge conflict', $body);
        self::assertStringNotContainsString('**Round:**', $body);
        self::assertStringNotContainsString('**Failed checks:**', $body);
    }

    public function test_a_check_name_stays_inside_its_code_span(): void
    {
        $this->pullRequest->failedChecks = ["lint`\n@octocat [x](https://example.com)", 'e2e'];
        $this->em->flush();

        $this->handle($this->pending());

        $body = $this->commenter->comments[0][1];
        self::assertStringContainsString("**Failed checks:** `lint@octocat [x](https://example.com)`, `e2e`\n", $body);
        $line = explode("\n", $body)[3];
        self::assertStringStartsWith('**Failed checks:**', $line);
        self::assertSame(4, substr_count($line, '`'));
    }

    public function test_an_unknown_reason_reads_as_a_generic_fix(): void
    {
        $this->handle($this->pending(reason: null));

        self::assertStringContainsString('**Reason:** fix requested', $this->commenter->comments[0][1]);
    }

    public function test_a_permanent_failure_marks_the_comment_failed(): void
    {
        $this->commenter->failure = new PullRequestCommentFailed('permission', permanent: true);
        $comment = $this->pending();

        $this->handle($comment);

        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Failed, $comment->state);
        self::assertSame('permission', $comment->cause);
        self::assertEquals($this->clock->now(), $comment->failedAt);
        self::assertSame(1, $comment->attempts);
    }

    public function test_a_transient_failure_counts_the_attempt_and_rethrows(): void
    {
        $failure = new PullRequestCommentFailed('api_failed_http_status_502', permanent: false);
        $this->commenter->failure = $failure;
        $comment = $this->pending();

        try {
            $this->handle($comment);
            self::fail('Expected the transient failure to propagate.');
        } catch (PullRequestCommentFailed $e) {
            self::assertSame($failure, $e);
        }

        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
        self::assertSame(1, $comment->attempts);
        self::assertSame('api_failed_http_status_502', $comment->cause);
        self::assertNull($comment->failedAt);
    }

    /** @return iterable<string, array{int, int}> */
    public static function retryDelays(): iterable
    {
        yield 'the delay GitHub asked for' => [90, 90_000];
        yield 'no wait at all' => [0, 0];
        yield 'a delay of hours' => [7_200, 7_200_000];
        yield 'a delay past the cap' => [172_800, 86_400_000];
    }

    #[DataProvider('retryDelays')]
    public function test_a_transient_failure_with_a_delay_asks_messenger_to_wait_within_its_retry_budget(int $seconds, int $expectedMilliseconds): void
    {
        $failure = new PullRequestCommentFailed('api_failed_rate_limited', permanent: false, retryAfterSeconds: $seconds);
        $this->commenter->failure = $failure;
        $comment = $this->pending();

        try {
            $this->handle($comment);
            self::fail('Expected the transient failure to propagate.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame($expectedMilliseconds, $e->getRetryDelay());
            self::assertFalse($e->forceRetry());
            self::assertSame($failure, $e->getPrevious());
        }

        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
        self::assertSame(1, $comment->attempts);
        self::assertSame('api_failed_rate_limited', $comment->cause);
    }

    public function test_a_failed_comment_retried_by_hand_is_posted(): void
    {
        $comment = $this->pending();
        $comment->state = PullRequestCommentState::Failed;
        $comment->cause = 'api_failed_http_status_502';
        $comment->failedAt = $this->clock->now();
        $comment->attempts = 4;
        $this->em->flush();

        $this->handle($comment);

        self::assertCount(1, $this->commenter->lookups);
        self::assertCount(1, $this->commenter->comments);
        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Posted, $comment->state);
        self::assertNull($comment->cause);
        self::assertNull($comment->failedAt);
        self::assertSame(5, $comment->attempts);
    }

    public function test_a_failed_comment_retried_by_hand_that_fails_again_is_pending_until_messenger_gives_up(): void
    {
        $this->commenter->failure = new PullRequestCommentFailed('api_failed_http_status_502', permanent: false);
        $comment = $this->pending();
        $comment->state = PullRequestCommentState::Failed;
        $comment->failedAt = $this->clock->now();
        $this->em->flush();

        try {
            $this->handle($comment);
            self::fail('Expected the transient failure to propagate.');
        } catch (PullRequestCommentFailed) {
        }

        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
        self::assertNull($comment->failedAt);
    }

    public function test_an_unexpected_error_leaves_the_comment_pending_and_propagates(): void
    {
        $failure = new \RuntimeException('connection reset');
        $this->commenter->failure = $failure;
        $comment = $this->pending();

        try {
            $this->handle($comment);
            self::fail('Expected the error to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
        self::assertNull($comment->failedAt);
        self::assertNull($comment->cause);
        // Stored before the forge call, so the retry of a try that died after its post looks for the marker.
        self::assertSame(1, $comment->attempts);
    }

    public function test_a_comment_follows_its_pull_request_through_a_repository_rename(): void
    {
        $comment = $this->pending(forgePullRequestId: $this->pullRequest->id);
        $this->pullRequest->repository = 'acme/renamed';
        $this->em->flush();

        $this->handle($comment);

        self::assertCount(1, $this->commenter->comments);
        self::assertSame($this->pullRequest, $this->commenter->comments[0][0]);
        self::assertSame(PullRequestCommentState::Posted, $this->reload($comment)->state);
    }

    public function test_a_comment_whose_pull_request_row_is_gone_falls_back_to_the_key(): void
    {
        $comment = $this->pending(forgePullRequestId: Uuid::v7());

        $this->handle($comment);

        self::assertCount(1, $this->commenter->comments);
        self::assertSame($this->pullRequest, $this->commenter->comments[0][0]);
    }

    public function test_an_untracked_pull_request_marks_the_comment_failed(): void
    {
        $comment = $this->pending(number: 99);

        $this->handle($comment);

        self::assertSame([], $this->commenter->comments);
        $comment = $this->reload($comment);
        self::assertSame(PullRequestCommentState::Failed, $comment->state);
        self::assertSame('unknown_pull_request', $comment->cause);
        self::assertEquals($this->clock->now(), $comment->failedAt);
    }

    public function test_a_comment_already_posted_is_not_posted_again(): void
    {
        $comment = $this->pending();
        $comment->state = PullRequestCommentState::Posted;
        $this->em->flush();

        $this->handle($comment);

        self::assertSame([], $this->commenter->comments);
    }

    private function pending(?string $reason = 'checks-failed', int $number = 5, ?int $fixRound = null, ?Uuid $forgePullRequestId = null, ?Uuid $runId = null): PullRequestComment
    {
        $comment = new PullRequestComment(
            project: $this->project,
            runId: $runId ?? Uuid::v7(),
            cardId: $this->card->id ?? throw new \LogicException('A persisted card has an id.'),
            forge: 'github',
            repository: 'Acme/Widgets',
            number: $number,
            headSha: 'abc1234',
            reason: $reason,
            createdAt: $this->clock->now(),
            fixRound: $fixRound,
            forgePullRequestId: $forgePullRequestId,
        );
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    /** A fix run whose work request the rule opened. */
    private function fixRun(string $ruleId): Uuid
    {
        $cardId = $this->card->id ?? throw new \LogicException('A persisted card has an id.');
        $request = new WorkRequest($this->project, WorkSubject::CARD, $cardId, $this->card->number, 'fix', null, $ruleId, $this->clock->now());
        $this->em->persist($request);
        $this->em->flush();
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId,
            cardNumber: $this->card->number,
            workKind: 'fix',
            state: WorkerRunState::Queued,
            workRequestId: $request->id,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run->id ?? throw new \LogicException('A flushed run has an id.');
    }

    private function budgets(string $ruleId, int $limit): RuleBudgets
    {
        $budgets = $this->createStub(RuleBudgets::class);
        $budgets->method('limit')->willReturnCallback(static fn (Uuid $projectId, string $asked): ?int => $asked === $ruleId ? $limit : null);

        return $budgets;
    }

    private function handle(PullRequestComment $comment, ?RuleBudgets $budgets = null): void
    {
        $comments = self::getContainer()->get(PullRequestCommentRepository::class);
        self::assertInstanceOf(PullRequestCommentRepository::class, $comments);
        $pullRequests = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $pullRequests);
        $body = new FixRunCommentBody(
            self::getContainer()->get(WorkerRunRepository::class),
            self::getContainer()->get(WorkRequestRepository::class),
            $budgets ?? $this->createStub(RuleBudgets::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(TranslatorInterface::class),
            'en',
        );

        $handler = new PostPullRequestCommentHandler(
            pullRequestComments: $comments,
            forgePullRequests: $pullRequests,
            commenters: new PullRequestCommenters([$this->commenter]),
            body: $body,
            em: $this->em,
            clock: $this->clock,
            logger: new NullLogger(),
        );

        $handler(new PostPullRequestCommentCommand($comment->id ?? throw new \LogicException('A flushed comment has an id.')));
    }

    private function reload(PullRequestComment $comment): PullRequestComment
    {
        $this->em->refresh($comment);

        return $comment;
    }
}
