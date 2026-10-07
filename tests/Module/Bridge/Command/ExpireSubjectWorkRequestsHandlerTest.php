<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ExpireSubjectWorkRequestsCommand;
use App\Module\Bridge\Command\ExpireSubjectWorkRequestsHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Bridge\WorkSubject\RecordingWorkSubjectHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class ExpireSubjectWorkRequestsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01 12:00:00';

    public function test_the_open_requests_of_other_subjects_past_the_timeout_expire(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-subjects@example.com'), 'Expire Subjects');
        $lapsed = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00'), subject: $this->analysis());
        $recent = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 10:00:01'), subject: $this->analysis());
        $claimed = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'), subject: $this->analysis());
        $card = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 08:00:00'));
        $subjects = new RecordingWorkSubjectHandler();

        self::assertSame(1, $this->handler($subjects)(new ExpireSubjectWorkRequestsCommand()));

        $em->clear();
        self::assertSame(WorkRequestState::Expired, $em->find(WorkRequest::class, $lapsed->id)?->state);
        foreach ([[$recent, WorkRequestState::Open], [$claimed, WorkRequestState::Claimed], [$card, WorkRequestState::Open]] as [$request, $state]) {
            self::assertSame($state, $em->find(WorkRequest::class, $request->id)?->state);
        }
        self::assertSame([(string) $lapsed->id], array_map(static fn (WorkRequest $request): string => (string) $request->id, $subjects->expired));
        self::assertSame([(string) $lapsed->id], array_column($this->outboxPayloads(), 'workRequestId'));
    }

    public function test_a_tick_with_nothing_to_expire_answers_zero(): void
    {
        self::bootKernel();

        self::assertSame(0, $this->handler(new RecordingWorkSubjectHandler())(new ExpireSubjectWorkRequestsCommand()));
    }

    /** A bridge can claim the request between the read and the expiry. Its claim stands. */
    public function test_an_expiry_only_if_open_leaves_a_claimed_request(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-claimed@example.com'), 'Expire Claimed');
        $claimed = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, subject: $this->analysis());
        $subjects = new RecordingWorkSubjectHandler();

        self::assertFalse($this->withdraw($subjects)(new WithdrawWorkRequestCommand($claimed->id ?? throw new \LogicException(), WorkRequestState::Expired, onlyIfOpen: true)));

        $em->clear();
        self::assertSame(WorkRequestState::Claimed, $em->find(WorkRequest::class, $claimed->id)?->state);
        self::assertSame([], $subjects->expired);
    }

    private function analysis(): WorkSubject
    {
        return new WorkSubject(RecordingWorkSubjectHandler::TYPE, Uuid::v7());
    }

    private function handler(RecordingWorkSubjectHandler $subjects): ExpireSubjectWorkRequestsHandler
    {
        return new ExpireSubjectWorkRequestsHandler(
            $this->service(WorkRequestRepository::class),
            $this->withdraw($subjects),
            new MockClock(self::NOW),
            120,
        );
    }

    private function withdraw(RecordingWorkSubjectHandler $subjects): WithdrawWorkRequestHandler
    {
        return new WithdrawWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock(self::NOW),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            new WorkSubjectHandlers([$subjects]),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT payload FROM outbox_events WHERE type = 'bridge.work_request' ORDER BY sequence",
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
