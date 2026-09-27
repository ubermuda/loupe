<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Messenger;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Messenger\RefreshPullRequestStateHandler;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestStateReaders;
use App\Module\Forge\Service\PullRequestUnreadable;
use App\Module\Project\Entity\Project;
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

final class RefreshPullRequestStateHandlerTest extends KernelTestCase
{
    private const string NOW = '2026-09-27 12:00:00';

    private EntityManagerInterface $em;
    private FakePullRequestStateReader $reader;
    private MockClock $clock;
    private RecordingLogger $logger;

    /** @var list<PullRequestStateChanged> */
    private array $changes = [];

    private RefreshPullRequestStateHandler $handler;

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
        $this->clock = new MockClock(self::NOW);
        $this->logger = new RecordingLogger();
        $this->handler = new RefreshPullRequestStateHandler(
            $forgePullRequests,
            new PullRequestStateReaders([$this->reader]),
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
        self::assertSame([], $this->sent());
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

    public function test_a_row_read_since_the_request_is_skipped(): void
    {
        $row = $this->row(refreshedAt: '2026-09-27 11:59:00');

        $this->handle($row, '2026-09-27 11:59:00');
        $this->handle($row, '2026-09-27 11:58:59');

        self::assertSame(0, $this->reader->reads);
        self::assertSame([], $this->changes);
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

    public function test_a_missing_row_and_a_forge_with_no_reader_are_skipped(): void
    {
        $gitlab = $this->row(forge: 'gitlab');

        ($this->handler)(new RefreshPullRequestState('0199a0b8-0000-7000-8000-000000000000', new \DateTimeImmutable(self::NOW)));
        ($this->handler)(new RefreshPullRequestState('not-a-uuid', new \DateTimeImmutable(self::NOW)));
        $this->handle($gitlab, self::NOW);

        self::assertSame(0, $this->reader->reads);
        self::assertNull($this->reload($gitlab)->refreshedAt);
    }

    public function test_an_unknown_mergeability_is_read_again_after_30_60_120_and_240_seconds_then_left(): void
    {
        $row = $this->row();
        $this->reader->answers = array_fill(0, 5, new PullRequestSnapshot());
        $message = new RefreshPullRequestState((string) $row->id, new \DateTimeImmutable(self::NOW));

        $delays = [];
        for ($read = 1; $read <= 5; ++$read) {
            ($this->handler)($message);
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
            $message = $next;
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
        self::assertEquals(new \DateTimeImmutable(self::NOW), $fresh->refreshedAt);
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

    public function test_the_container_routes_the_message_to_the_handler(): void
    {
        $handler = self::getContainer()->get(RefreshPullRequestStateHandler::class);

        self::assertInstanceOf(RefreshPullRequestStateHandler::class, $handler);
    }

    private function handle(ForgePullRequest $row, string $requestedAt): void
    {
        ($this->handler)(new RefreshPullRequestState((string) $row->id, new \DateTimeImmutable($requestedAt)));
        $this->em->clear();
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
