<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Command;

use App\Module\Account\Entity\User;
use App\Module\Forge\Command\ReadPullRequestStateCommand;
use App\Module\Forge\Command\ReadPullRequestStateHandler;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Messenger\RefreshPullRequestStateHandler;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ApprovalCoverage;
use App\Module\Forge\Service\ApprovalCoverageReaders;
use App\Module\Forge\Service\PullRequestStateReaders;
use App\Module\Forge\Service\PullRequestUnreadable;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Forge\FakeApprovalCoverageReader;
use App\Tests\Module\Forge\FakePullRequestStateReader;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ReadPullRequestStateHandlerTest extends KernelTestCase
{
    private const string NOW = '2026-09-27 12:00:00';

    private EntityManagerInterface $em;
    private FakePullRequestStateReader $reader;
    private FakeApprovalCoverageReader $coverage;
    private MockClock $clock;
    private RecordingLogger $logger;

    /** @var list<PullRequestStateChanged> */
    private array $changes = [];

    private ReadPullRequestStateHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $forgePullRequests = $container->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $forgePullRequests);
        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $events = new EventDispatcher();
        $events->addListener(PullRequestStateChanged::class, function (PullRequestStateChanged $event): void {
            $this->changes[] = $event;
        });

        $this->reader = new FakePullRequestStateReader();
        $this->coverage = new FakeApprovalCoverageReader();
        $this->clock = new MockClock(self::NOW);
        $this->logger = new RecordingLogger();
        $this->handler = new ReadPullRequestStateHandler(
            $forgePullRequests,
            new PullRequestStateReaders([$this->reader]),
            new ApprovalCoverageReaders([$this->coverage]),
            $em,
            $bus,
            $events,
            $this->clock,
            $this->logger,
        );
    }

    public function test_a_read_applies_the_snapshot_and_announces_the_change(): void
    {
        $row = $this->row();
        $read = new PullRequestSnapshot(headSha: 'abc', checks: PullRequestChecks::Passed, mergeability: PullRequestMergeability::Mergeable);
        $this->reader->answers = [$read];

        $this->handle($row, self::NOW);

        $fresh = $this->reload($row);
        self::assertTrue($fresh->snapshot()->equals($read));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $fresh->refreshedAt);
        self::assertCount(1, $this->changes);
        self::assertSame((string) $row->id, (string) $this->changes[0]->pullRequest->id);
        self::assertTrue($this->changes[0]->previous->equals(new PullRequestSnapshot()));
        self::assertTrue($this->changes[0]->current->equals($read));
        self::assertNull($this->changes[0]->reviewVerdict);
        self::assertSame([], $this->sent());
    }

    public function test_a_verdict_rides_the_one_event_of_a_read_that_changed_the_state(): void
    {
        $row = $this->row();
        $read = new PullRequestSnapshot(headSha: 'abc');
        $this->reader->answers = [$read];

        $this->handle($row, self::NOW, verdict: PullRequestReview::Approved);

        self::assertCount(1, $this->changes);
        self::assertSame((string) $row->id, (string) $this->changes[0]->pullRequest->id);
        self::assertTrue($this->changes[0]->previous->equals(new PullRequestSnapshot()));
        self::assertTrue($this->changes[0]->current->equals($read));
        self::assertSame(PullRequestReview::Approved, $this->changes[0]->reviewVerdict);
        $sent = $this->sent();
        self::assertCount(1, $sent, 'The unknown mergeability is read again.');
        $retry = $sent[0]->getMessage();
        self::assertInstanceOf(RefreshPullRequestState::class, $retry);
        self::assertNull($retry->verdict, 'A mergeability retry does not announce the review twice.');
    }

    public function test_a_verdict_announces_the_review_when_nothing_changed(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestSnapshot()];

        $this->handle($row, self::NOW, verdict: PullRequestReview::Approved);

        self::assertSame(1, $this->reader->reads);
        $this->assertVerdictOnly(PullRequestReview::Approved, new PullRequestSnapshot());
    }

    public function test_a_skipped_read_still_announces_the_review_with_the_stored_state(): void
    {
        $row = $this->row(refreshedAt: '2026-09-27 11:59:00');
        $row->headSha = 'stored';
        $row->mergeability = PullRequestMergeability::Conflicting;
        $this->em->flush();

        $this->handle($row, '2026-09-27 11:58:00', verdict: PullRequestReview::Approved);

        self::assertSame(0, $this->reader->reads);
        $this->assertVerdictOnly(PullRequestReview::Approved, new PullRequestSnapshot(headSha: 'stored', mergeability: PullRequestMergeability::Conflicting));
    }

    public function test_a_failed_read_still_announces_the_review_with_the_stored_state(): void
    {
        $row = $this->row();
        $row->headSha = 'stored';
        $this->em->flush();
        $this->reader->answers = [new PullRequestUnreadable('no_installation')];

        $this->handle($row, self::NOW, verdict: PullRequestReview::ChangesRequested);

        self::assertSame(1, $this->reader->reads);
        $this->assertVerdictOnly(PullRequestReview::ChangesRequested, new PullRequestSnapshot(headSha: 'stored'));
    }

    public function test_a_transient_failure_leaves_the_review_to_the_retry(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestUnreadable('api_failed_transport', transient: true), new PullRequestSnapshot()];

        try {
            $this->handle($row, self::NOW, verdict: PullRequestReview::Approved);
            self::fail('A transient failure must reach the retry strategy.');
        } catch (PullRequestUnreadable) {
        }

        self::assertSame(1, $this->reader->reads);
        self::assertSame([], $this->changes);

        $this->handle($row, self::NOW, verdict: PullRequestReview::Approved);

        self::assertSame(2, $this->reader->reads);
        $this->assertVerdictOnly(PullRequestReview::Approved, new PullRequestSnapshot());
    }

    public function test_a_redelivered_review_announces_its_verdict_once(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestSnapshot(), new PullRequestSnapshot()];

        $this->handle($row, self::NOW, PullRequestReview::ChangesRequested, '101');
        $this->handle($row, self::NOW, PullRequestReview::ChangesRequested, '101');
        $this->clock->modify('+1 minute');
        $this->handle($row, '2026-09-27 12:01:00', PullRequestReview::ChangesRequested, '101');

        self::assertSame(2, $this->reader->reads, 'The redelivered message still reads the pull request.');
        $this->assertVerdictOnly(PullRequestReview::ChangesRequested, new PullRequestSnapshot());
        self::assertSame(['101'], $this->reload($row)->announcedReviewIds);
    }

    public function test_two_reviews_announce_two_verdicts(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestSnapshot()];

        $this->handle($row, self::NOW, PullRequestReview::ChangesRequested, '101');
        $this->handle($row, self::NOW, PullRequestReview::ChangesRequested, '102');

        self::assertSame([PullRequestReview::ChangesRequested, PullRequestReview::ChangesRequested], array_map(static fn (PullRequestStateChanged $change): ?PullRequestReview => $change->reviewVerdict, $this->changes));
        self::assertSame(['101', '102'], $this->reload($row)->announcedReviewIds);
    }

    public function test_a_redelivery_that_crosses_a_newer_review_announces_nothing(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestSnapshot()];

        $this->handle($row, self::NOW, PullRequestReview::ChangesRequested, '101');
        $this->handle($row, self::NOW, PullRequestReview::Approved, '102');
        $this->handle($row, self::NOW, PullRequestReview::ChangesRequested, '101');

        self::assertSame([PullRequestReview::ChangesRequested, PullRequestReview::Approved], array_map(static fn (PullRequestStateChanged $change): ?PullRequestReview => $change->reviewVerdict, $this->changes));
        self::assertSame(['101', '102'], $this->reload($row)->announcedReviewIds);
    }

    /** GitHub numbers a review when it starts as pending, so a lower id can be submitted after a higher one. */
    public function test_a_review_submitted_after_a_newer_id_still_announces(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestSnapshot()];

        $this->handle($row, self::NOW, PullRequestReview::Approved, '102');
        $this->handle($row, self::NOW, PullRequestReview::ChangesRequested, '101');

        self::assertSame([PullRequestReview::Approved, PullRequestReview::ChangesRequested], array_map(static fn (PullRequestStateChanged $change): ?PullRequestReview => $change->reviewVerdict, $this->changes));
    }

    public function test_it_keeps_the_newest_announced_review_ids(): void
    {
        $row = $this->row();
        foreach (range(1, ForgePullRequest::ANNOUNCED_REVIEW_LIMIT + 1) as $id) {
            $row->recordAnnouncedReview((string) $id);
        }
        $row->recordAnnouncedReview('2');

        self::assertCount(ForgePullRequest::ANNOUNCED_REVIEW_LIMIT, $row->announcedReviewIds);
        self::assertFalse($row->hasAnnouncedReview('1'));
        self::assertSame('2', $row->announcedReviewIds[ForgePullRequest::ANNOUNCED_REVIEW_LIMIT - 1]);
    }

    public function test_a_verdict_with_no_review_id_announces_each_time(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestSnapshot()];

        $this->handle($row, self::NOW, PullRequestReview::Approved);
        $this->handle($row, self::NOW, PullRequestReview::Approved);

        self::assertCount(2, $this->changes);
        self::assertSame([], $this->reload($row)->announcedReviewIds);
    }

    public function test_a_transient_failure_keeps_the_review_id_for_the_retry(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestUnreadable('api_failed_transport', transient: true), new PullRequestSnapshot()];

        try {
            $this->handle($row, self::NOW, PullRequestReview::Approved, '101');
            self::fail('A transient failure must reach the retry strategy.');
        } catch (PullRequestUnreadable) {
        }
        self::assertSame([], $this->reload($row)->announcedReviewIds);

        $this->handle($row, self::NOW, PullRequestReview::Approved, '101');

        $this->assertVerdictOnly(PullRequestReview::Approved, new PullRequestSnapshot());
    }

    public function test_a_missing_row_and_a_forge_with_no_reader_announce_no_review(): void
    {
        $gitlab = $this->row(forge: 'gitlab', refreshedAt: '2026-09-27 11:59:00');

        ($this->handler)(new ReadPullRequestStateCommand('0199a0b8-0000-7000-8000-000000000000', new \DateTimeImmutable(self::NOW), verdict: PullRequestReview::Approved));
        $this->handle($gitlab, self::NOW, verdict: PullRequestReview::Approved);
        $this->handle($gitlab, '2026-09-27 11:58:00', verdict: PullRequestReview::Approved);

        self::assertSame(0, $this->reader->reads);
        self::assertSame([], $this->changes);
    }

    public function test_a_read_that_changes_nothing_announces_nothing(): void
    {
        $row = $this->row();
        $read = new PullRequestSnapshot(mergeability: PullRequestMergeability::Mergeable);
        $this->reader->answers = [$read, $read];
        $this->handle($row, self::NOW);
        $this->clock->modify('+1 minute');

        $this->handle($row, '2026-09-27 12:00:30');

        self::assertSame(2, $this->reader->reads);
        self::assertCount(1, $this->changes);
        self::assertEquals(new \DateTimeImmutable('2026-09-27 12:01:00'), $this->reload($row)->refreshedAt);
    }

    public function test_an_open_and_merge_time_read_for_the_first_time_is_stored_and_announces_nothing(): void
    {
        $row = $this->row();
        $opened = new \DateTimeImmutable('2026-09-20 08:00:00');
        $merged = new \DateTimeImmutable('2026-09-21 09:30:00');
        $this->reader->answers = [
            new PullRequestSnapshot(state: PullRequestState::Merged),
            new PullRequestSnapshot(state: PullRequestState::Merged, openedAt: $opened, mergedAt: $merged),
        ];
        $this->handle($row, self::NOW);
        $this->clock->modify('+1 minute');

        $this->handle($row, '2026-09-27 12:00:30');

        self::assertSame(2, $this->reader->reads);
        self::assertCount(1, $this->changes);
        $fresh = $this->reload($row);
        self::assertEquals($opened, $fresh->openedAt);
        self::assertEquals($merged, $fresh->mergedAt);
    }

    public function test_a_row_read_since_the_request_is_skipped(): void
    {
        $row = $this->row(refreshedAt: '2026-09-27 11:59:00');

        $this->handle($row, '2026-09-27 11:59:00');
        $this->handle($row, '2026-09-27 11:58:59');

        self::assertSame(0, $this->reader->reads);
        self::assertSame([], $this->changes);
    }

    public function test_the_skip_rule_keeps_microseconds_through_the_database(): void
    {
        $row = $this->row(refreshedAt: '2026-09-27 11:59:00.200000');
        $this->em->clear();

        $this->handle($row, '2026-09-27 11:59:00.150000');

        self::assertSame(0, $this->reader->reads);
        self::assertSame('2026-09-27 11:59:00.200000', $this->reload($row)->refreshedAt?->format('Y-m-d H:i:s.u'));
    }

    public function test_a_row_read_before_the_request_is_read_again(): void
    {
        $row = $this->row(refreshedAt: '2026-09-27 11:59:00');

        $this->handle($row, '2026-09-27 11:59:00.5');

        self::assertSame(1, $this->reader->reads);
    }

    public function test_a_hint_that_arrives_during_a_read_is_read_again(): void
    {
        $row = $this->row();
        $this->reader->duringRead = fn () => $this->clock->modify('+10 seconds');

        $this->handle($row, self::NOW);
        $this->handle($row, '2026-09-27 12:00:05');

        self::assertSame(2, $this->reader->reads);
        self::assertEquals(new \DateTimeImmutable('2026-09-27 12:00:10'), $this->reload($row)->refreshedAt);
    }

    public function test_a_head_that_the_approval_covers_moves_the_covered_sha(): void
    {
        $row = $this->approvedRow();
        $this->reader->answers = [$this->approvedRead('merged1')];
        $this->coverage->answers = [ApprovalCoverage::Covered];

        $this->handle($row, self::NOW);

        $fresh = $this->reload($row);
        self::assertSame(1, $this->coverage->reads);
        self::assertSame('merged1', $fresh->coveredSha);
        self::assertNull($fresh->uncoveredSha);
        self::assertCount(1, $this->changes);
        self::assertSame('merged1', $this->changes[0]->pullRequest->coveredSha);
    }

    public function test_a_head_that_the_approval_does_not_cover_is_judged_once(): void
    {
        $row = $this->approvedRow();
        $this->reader->answers = [$this->approvedRead('pushed1'), $this->approvedRead('pushed1')];
        $this->coverage->answers = [ApprovalCoverage::NotCovered];

        $this->handle($row, self::NOW);
        $this->clock->modify('+1 minute');
        $this->handle($row, '2026-09-27 12:00:30');

        $fresh = $this->reload($row);
        self::assertSame(2, $this->reader->reads);
        self::assertSame(1, $this->coverage->reads);
        self::assertSame('approved1', $fresh->coveredSha);
        self::assertSame('pushed1', $fresh->uncoveredSha);
    }

    public function test_an_unknown_coverage_stores_nothing_and_is_asked_again(): void
    {
        $row = $this->approvedRow();
        $this->reader->answers = [$this->approvedRead('pushed1'), $this->approvedRead('pushed1')];
        $this->coverage->answers = [ApprovalCoverage::Unknown, ApprovalCoverage::Unknown];

        $this->handle($row, self::NOW);
        $fresh = $this->reload($row);
        self::assertSame('approved1', $fresh->coveredSha);
        self::assertNull($fresh->uncoveredSha);

        $this->clock->modify('+1 minute');
        $this->handle($row, '2026-09-27 12:00:30');

        self::assertSame(2, $this->coverage->reads);
    }

    public function test_coverage_is_not_asked_without_a_head_after_an_open_approval(): void
    {
        $covered = $this->approvedRow();
        $unapproved = $this->row();
        $merged = $this->approvedRow();
        $this->reader->answers = [
            $this->approvedRead('approved1'),
            new PullRequestSnapshot(headSha: 'pushed1', mergeability: PullRequestMergeability::Mergeable),
            new PullRequestSnapshot(state: PullRequestState::Merged, headSha: 'pushed1', review: PullRequestReview::Approved, approvalSha: 'approved1', approvalId: 'review1'),
        ];

        $this->handle($covered, self::NOW);
        $this->handle($unapproved, self::NOW);
        $this->handle($merged, self::NOW);

        self::assertSame(3, $this->reader->reads);
        self::assertSame(0, $this->coverage->reads);
    }

    public function test_a_new_approval_that_moves_only_the_covered_sha_is_announced(): void
    {
        $row = $this->approvedRow();
        $this->reader->answers = [
            $this->approvedRead('pushed1'),
            $this->approvedRead('pushed1', approvalId: 'review2', approvalSha: 'pushed1'),
            $this->approvedRead('pushed1', approvalId: 'review2', approvalSha: 'pushed1'),
        ];
        $this->coverage->answers = [ApprovalCoverage::NotCovered];

        $this->handle($row, self::NOW);
        $this->clock->modify('+1 minute');
        $this->handle($row, '2026-09-27 12:00:30');
        self::assertCount(2, $this->changes);
        self::assertTrue($this->changes[1]->current->equals($this->changes[1]->previous));
        self::assertSame('pushed1', $this->changes[1]->pullRequest->coveredSha);
        self::assertNull($this->changes[1]->pullRequest->uncoveredSha);

        $this->clock->modify('+1 minute');
        $this->handle($row, '2026-09-27 12:01:30');

        self::assertCount(2, $this->changes);
        self::assertSame(1, $this->coverage->reads);
    }

    public function test_a_missing_row_and_a_forge_with_no_reader_are_skipped(): void
    {
        $gitlab = $this->row(forge: 'gitlab');

        ($this->handler)(new ReadPullRequestStateCommand('0199a0b8-0000-7000-8000-000000000000', new \DateTimeImmutable(self::NOW)));
        ($this->handler)(new ReadPullRequestStateCommand('not-a-uuid', new \DateTimeImmutable(self::NOW)));
        $this->handle($gitlab, self::NOW);

        self::assertSame(0, $this->reader->reads);
        self::assertNull($this->reload($gitlab)->refreshedAt);
    }

    public function test_an_unknown_mergeability_is_read_again_after_30_60_120_and_240_seconds_then_left(): void
    {
        $row = $this->row();
        $this->reader->answers = array_fill(0, 5, new PullRequestSnapshot());
        $command = new ReadPullRequestStateCommand((string) $row->id, new \DateTimeImmutable(self::NOW));

        $delays = [];
        for ($read = 1; $read <= 5; ++$read) {
            ($this->handler)($command);
            $this->em->clear();
            $sent = $this->sent();
            if (\count($sent) < $read) {
                break;
            }
            $envelope = $sent[$read - 1];
            $next = $envelope->getMessage();
            self::assertInstanceOf(RefreshPullRequestState::class, $next);
            $delay = $envelope->last(DelayStamp::class)?->getDelay();
            $delays[] = $delay;
            $fresh = $this->reload($row);
            self::assertSame($read, $fresh->refreshAttempts);
            self::assertEquals($this->clock->now()->modify('+'.($delay ?? 0) / 1000 .' seconds'), $fresh->nextRefreshAt);
            self::assertEquals($fresh->nextRefreshAt, $next->requestedAt);
            $command = new ReadPullRequestStateCommand($next->pullRequestId, $next->requestedAt);
        }

        self::assertSame(5, $this->reader->reads, 'A re-queued message is never skipped as already read.');
        self::assertSame([30_000, 60_000, 120_000, 240_000], $delays);
        $fresh = $this->reload($row);
        self::assertSame(4, $fresh->refreshAttempts);
        self::assertNull($fresh->nextRefreshAt);
    }

    public function test_a_known_mergeability_resets_the_retries(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestSnapshot(), new PullRequestSnapshot(mergeability: PullRequestMergeability::Conflicting)];
        $this->handle($row, self::NOW);
        self::assertSame(1, $this->reload($row)->refreshAttempts);

        $this->handle($row, '2026-09-27 12:00:30');

        $fresh = $this->reload($row);
        self::assertSame(0, $fresh->refreshAttempts);
        self::assertNull($fresh->nextRefreshAt);
        self::assertCount(1, $this->sent());
    }

    public function test_a_closed_pull_request_is_not_read_again_for_its_mergeability(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestSnapshot(state: PullRequestState::Merged)];

        $this->handle($row, self::NOW);

        self::assertSame([], $this->sent());
        self::assertSame(0, $this->reload($row)->refreshAttempts);
    }

    public function test_an_unreadable_pull_request_keeps_its_state_and_is_logged(): void
    {
        $row = $this->row();
        $row->headSha = 'abc';
        $this->em->flush();
        $this->reader->answers = [new PullRequestUnreadable('no_installation')];

        $this->handle($row, self::NOW);

        $fresh = $this->reload($row);
        self::assertSame('abc', $fresh->headSha);
        self::assertNull($fresh->refreshedAt);
        self::assertSame([], $this->changes);
        self::assertSame([], $this->sent());
        self::assertSame(
            [['message' => 'forge.pull_request_refresh_failed', 'context' => [
                'pullRequestId' => (string) $row->id,
                'forge' => FakePullRequestStateReader::FORGE,
                'reason' => 'no_installation',
            ]]],
            array_map(static fn (array $record): array => ['message' => $record['message'], 'context' => $record['context']], $this->logger->records),
        );
    }

    public function test_an_unreadable_pull_request_keeps_the_time_of_its_last_read(): void
    {
        $row = $this->row(refreshedAt: '2026-09-27 11:00:00');
        $this->reader->answers = [new PullRequestUnreadable('not_found')];

        $this->handle($row, self::NOW);

        self::assertEquals(new \DateTimeImmutable('2026-09-27 11:00:00'), $this->reload($row)->refreshedAt);
    }

    public function test_a_transient_failure_is_thrown_for_a_retry_and_leaves_the_row_unread(): void
    {
        $row = $this->row();
        $this->reader->answers = [new PullRequestUnreadable('api_failed_transport', transient: true)];

        try {
            $this->handle($row, self::NOW);
            self::fail('A transient failure must reach the retry strategy.');
        } catch (PullRequestUnreadable $e) {
            self::assertSame('api_failed_transport', $e->reason);
        }

        self::assertTrue($this->em->isOpen());
        self::assertNull($this->reload($row)->refreshedAt);
    }

    public function test_the_container_routes_the_message_to_the_handler(): void
    {
        $handler = self::getContainer()->get(RefreshPullRequestStateHandler::class);

        self::assertInstanceOf(RefreshPullRequestStateHandler::class, $handler);
    }

    private function handle(ForgePullRequest $row, string $requestedAt, ?PullRequestReview $verdict = null, ?string $reviewId = null): void
    {
        ($this->handler)(new ReadPullRequestStateCommand((string) $row->id, new \DateTimeImmutable($requestedAt), $verdict, $reviewId));
        $this->em->clear();
    }

    private function assertVerdictOnly(PullRequestReview $verdict, PullRequestSnapshot $stored): void
    {
        self::assertCount(1, $this->changes);
        self::assertSame($verdict, $this->changes[0]->reviewVerdict);
        self::assertTrue($this->changes[0]->previous->equals($stored));
        self::assertTrue($this->changes[0]->current->equals($stored));
    }

    private function row(string $forge = FakePullRequestStateReader::FORGE, ?string $refreshedAt = null): ForgePullRequest
    {
        $owner = new User(fullName: 'Riley', email: 'refresh-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'refresh-'.uniqid());
        $row = new ForgePullRequest($project, $forge, 'acme/widgets', 42);
        $row->refreshedAt = null === $refreshedAt ? null : new \DateTimeImmutable($refreshedAt);
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    /** An open pull request approved by `review1` on its head `approved1`. */
    private function approvedRow(): ForgePullRequest
    {
        $row = $this->row();
        $row->apply($this->approvedRead('approved1'));
        $this->em->flush();

        return $row;
    }

    private function approvedRead(string $head, string $approvalId = 'review1', string $approvalSha = 'approved1'): PullRequestSnapshot
    {
        return new PullRequestSnapshot(headSha: $head, mergeability: PullRequestMergeability::Mergeable, review: PullRequestReview::Approved, approvalSha: $approvalSha, approvalId: $approvalId);
    }

    private function reload(ForgePullRequest $row): ForgePullRequest
    {
        $this->em->clear();

        return $this->em->find(ForgePullRequest::class, $row->id) ?? throw new \LogicException('The row exists.');
    }

    /** @return list<Envelope> */
    private function sent(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values([...$transport->getSent()]);
    }
}
