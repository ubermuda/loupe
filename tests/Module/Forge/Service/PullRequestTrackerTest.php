<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestStateReaders;
use App\Module\Forge\Service\PullRequestTracker;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Forge\FakePullRequestStateReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PullRequestTrackerTest extends KernelTestCase
{
    private const string NOW = '2026-09-27 12:00:00';

    private EntityManagerInterface $em;
    private PullRequestTracker $tracker;
    private ForgePullRequestRepository $pullRequests;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $pullRequests = $container->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $pullRequests);
        $this->pullRequests = $pullRequests;

        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        // The container registry holds no reader for the fake forge, so the tracker is built by hand.
        $this->tracker = new PullRequestTracker(
            $pullRequests,
            new PullRequestStateReaders([new FakePullRequestStateReader()]),
            $bus,
            new MockClock(self::NOW),
        );
    }

    public function test_track_creates_one_lower_case_row_and_queues_a_refresh_of_it(): void
    {
        $project = $this->project();

        $this->tracker->track($project, FakePullRequestStateReader::FORGE, 'Acme/Widgets', 42);
        $this->tracker->track($project, FakePullRequestStateReader::FORGE, 'acme/widgets', 42);

        $rows = $this->pullRequests->findBy(['project' => $project]);
        self::assertCount(1, $rows);
        self::assertSame('acme/widgets', $rows[0]->repository);
        self::assertSame(42, $rows[0]->number);
        $sent = $this->sent();
        self::assertCount(2, $sent);
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RefreshPullRequestState::class, $message);
            self::assertSame((string) $rows[0]->id, $message->pullRequestId);
            self::assertEquals(new \DateTimeImmutable(self::NOW), $message->requestedAt);
            self::assertNull($envelope->last(DelayStamp::class));
        }
    }

    public function test_track_ignores_a_forge_no_reader_supports(): void
    {
        $project = $this->project();

        $this->tracker->track($project, 'gitlab', 'acme/widgets', 42);

        self::assertSame([], $this->pullRequests->findBy(['project' => $project]));
        self::assertSame([], $this->sent());
    }

    public function test_untrack_removes_the_row_whatever_the_case(): void
    {
        $project = $this->project();
        $this->tracker->track($project, FakePullRequestStateReader::FORGE, 'acme/widgets', 42);
        $this->tracker->track($project, FakePullRequestStateReader::FORGE, 'acme/widgets', 43);
        self::assertCount(2, $this->pullRequests->findBy(['project' => $project]));

        $this->tracker->untrack($project, FakePullRequestStateReader::FORGE, 'ACME/Widgets', 42);

        $rows = $this->pullRequests->findBy(['project' => $project]);
        self::assertCount(1, $rows);
        self::assertSame(43, $rows[0]->number);
    }

    public function test_refresh_queues_the_row_of_one_number_only(): void
    {
        $project = $this->project();
        $open = $this->row($project, 42);
        $this->row($project, 43);

        $this->tracker->refresh($project, FakePullRequestStateReader::FORGE, 'ACME/widgets', 42);

        self::assertSame([(string) $open->id], $this->queuedIds());
    }

    public function test_refresh_carries_the_review_verdict_and_leaves_it_off_by_default(): void
    {
        $project = $this->project();
        $this->row($project, 42);

        $this->tracker->refresh($project, FakePullRequestStateReader::FORGE, 'acme/widgets', 42, PullRequestReview::ChangesRequested);
        $this->tracker->refresh($project, FakePullRequestStateReader::FORGE, 'acme/widgets', 42);

        self::assertSame([PullRequestReview::ChangesRequested, null], array_map(static function (Envelope $envelope): ?PullRequestReview {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RefreshPullRequestState::class, $message);

            return $message->verdict;
        }, $this->sent()));
    }

    public function test_refresh_queues_a_closed_row_so_a_reopen_is_read(): void
    {
        $project = $this->project();
        $closed = $this->row($project, 44, state: PullRequestState::Closed);

        $this->tracker->refresh($project, FakePullRequestStateReader::FORGE, 'acme/widgets', 44);

        self::assertSame([(string) $closed->id], $this->queuedIds());
    }

    public function test_refresh_head_queues_every_open_row_on_that_commit(): void
    {
        $project = $this->project();
        $first = $this->row($project, 42, headSha: 'abc');
        $second = $this->row($project, 43, headSha: 'abc');
        $this->row($project, 44, headSha: 'def');
        $this->row($project, 45, headSha: 'abc', state: PullRequestState::Closed);
        $this->row($this->project(), 42, headSha: 'abc');

        $this->tracker->refreshHead($project, FakePullRequestStateReader::FORGE, 'Acme/Widgets', 'abc');

        $expected = [(string) $first->id, (string) $second->id];
        sort($expected);
        $actual = $this->queuedIds();
        sort($actual);
        self::assertSame($expected, $actual);
        foreach ($this->sent() as $envelope) {
            self::assertNull($envelope->last(DelayStamp::class));
        }
    }

    public function test_refresh_base_queues_every_open_row_on_that_branch_after_thirty_seconds(): void
    {
        $project = $this->project();
        $onMain = $this->row($project, 42, baseBranch: 'main');
        $this->row($project, 43, baseBranch: 'develop');

        $this->tracker->refreshBase($project, FakePullRequestStateReader::FORGE, 'acme/widgets', 'main');

        self::assertSame([(string) $onMain->id], $this->queuedIds());
        self::assertSame(30_000, $this->sent()[0]->last(DelayStamp::class)?->getDelay());
        $message = $this->sent()[0]->getMessage();
        self::assertInstanceOf(RefreshPullRequestState::class, $message);
        self::assertEquals(new \DateTimeImmutable('2026-09-27 12:00:30'), $message->requestedAt);
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'tracker-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'tracker-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function row(
        Project $project,
        int $number,
        ?string $headSha = null,
        ?string $baseBranch = null,
        PullRequestState $state = PullRequestState::Open,
    ): ForgePullRequest {
        $row = new ForgePullRequest($project, FakePullRequestStateReader::FORGE, 'acme/widgets', $number);
        $row->headSha = $headSha;
        $row->baseBranch = $baseBranch;
        $row->state = $state;
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    /** @return list<Envelope> */
    private function sent(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values([...$transport->getSent()]);
    }

    /** @return list<string> */
    private function queuedIds(): array
    {
        return array_map(static function (Envelope $envelope): string {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RefreshPullRequestState::class, $message);

            return $message->pullRequestId;
        }, $this->sent());
    }
}
