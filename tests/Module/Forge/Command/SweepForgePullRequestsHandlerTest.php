<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Command;

use App\Module\Account\Entity\User;
use App\Module\Forge\Command\SweepForgePullRequestsCommand;
use App\Module\Forge\Command\SweepForgePullRequestsHandler;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SweepForgePullRequestsHandlerTest extends KernelTestCase
{
    private const string NOW = '2026-09-27 12:00:00';

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $owner = new User(fullName: 'Riley', email: 'sweep-'.uniqid().'@example.com', password: 'hashed');
        $this->project = new Project($owner, 'sweep-'.uniqid());
        $em->persist($owner);
        $em->persist($this->project);
        $em->flush();
    }

    public function test_the_sweep_queues_each_open_row_unread_for_ten_minutes(): void
    {
        $never = $this->row(1, null);
        $stale = $this->row(2, '2026-09-27 11:49:59');
        $this->row(3, '2026-09-27 11:50:00');
        $this->row(4, '2026-09-27 11:59:00');
        $this->row(5, null, PullRequestState::Merged);
        $this->row(6, '2026-09-27 10:00:00', PullRequestState::Closed);

        $queued = $this->handler(batchSize: 500)(new SweepForgePullRequestsCommand());

        $expected = [(string) $never->id, (string) $stale->id];
        sort($expected);
        self::assertSame(2, $queued);
        self::assertSame($expected, $this->queuedIds());
        foreach ($this->sent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RefreshPullRequestState::class, $message);
            self::assertEquals(new \DateTimeImmutable(self::NOW), $message->requestedAt);
        }
    }

    public function test_the_sweep_walks_every_batch_in_one_tick(): void
    {
        $ids = [];
        for ($number = 1; $number <= 5; ++$number) {
            $ids[] = (string) $this->row($number, null)->id;
        }
        sort($ids);

        $queued = $this->handler(batchSize: 2)(new SweepForgePullRequestsCommand());

        self::assertSame(5, $queued);
        self::assertSame($ids, $this->queuedIds());
    }

    private function handler(int $batchSize): SweepForgePullRequestsHandler
    {
        $forgePullRequests = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $forgePullRequests);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return new SweepForgePullRequestsHandler($forgePullRequests, $bus, new MockClock(self::NOW), $batchSize);
    }

    private function row(int $number, ?string $refreshedAt, PullRequestState $state = PullRequestState::Open): ForgePullRequest
    {
        $row = new ForgePullRequest($this->project, 'github', 'acme/widgets', $number);
        $row->refreshedAt = null === $refreshedAt ? null : new \DateTimeImmutable($refreshedAt);
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

    /** @return list<string> sorted */
    private function queuedIds(): array
    {
        $ids = array_map(static function (Envelope $envelope): string {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RefreshPullRequestState::class, $message);

            return $message->pullRequestId;
        }, $this->sent());
        sort($ids);

        return $ids;
    }
}
