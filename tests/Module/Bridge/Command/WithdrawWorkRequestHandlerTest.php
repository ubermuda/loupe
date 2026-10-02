<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class WithdrawWorkRequestHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    /** @return iterable<string, array{WorkRequestState, WorkRequestState}> */
    public static function withdrawals(): iterable
    {
        yield 'open to cancelled' => [WorkRequestState::Open, WorkRequestState::Cancelled];
        yield 'claimed to expired' => [WorkRequestState::Claimed, WorkRequestState::Expired];
    }

    #[DataProvider('withdrawals')]
    public function test_a_live_request_withdraws_and_tells_the_bridges(WorkRequestState $from, WorkRequestState $to): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $request = $this->request('withdraw-live', $from);

        self::assertTrue($this->withdraw($request, $to));

        $this->em()->clear();
        $stored = $this->em()->find(WorkRequest::class, $request->id);
        self::assertInstanceOf(WorkRequest::class, $stored);
        self::assertSame($to, $stored->state);
        self::assertSame(self::NOW, $stored->settledAt?->format(\DateTimeInterface::ATOM));

        $payloads = $this->outboxPayloads();
        self::assertCount(1, $payloads);
        self::assertSame((string) $request->id, $payloads[0]['workRequestId']);
        self::assertSame($to->value, $payloads[0]['state']);
        self::assertArrayNotHasKey('claimToken', $payloads[0]);

        $record = $audit->record('bridge.work_request_withdrawn');
        self::assertSame((string) $request->id, $record->subject?->id);
        self::assertSame($to->value, $record->context['state']);
    }

    public function test_a_settled_request_changes_nothing(): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $request = $this->request('withdraw-settled', WorkRequestState::Done);

        self::assertFalse($this->withdraw($request, WorkRequestState::Cancelled));

        $this->em()->clear();
        $stored = $this->em()->find(WorkRequest::class, $request->id);
        self::assertSame(WorkRequestState::Done, $stored?->state);
        self::assertNull($stored->settledAt);
        self::assertSame([], $this->outboxPayloads());
        self::assertSame([], $audit->records('bridge.work_request_withdrawn'));
    }

    /** The row is read fresh under the lock, so a write that bypassed the ORM is seen. */
    public function test_a_request_settled_behind_the_managed_copy_changes_nothing(): void
    {
        $this->boot();
        $request = $this->request('withdraw-fresh', WorkRequestState::Open);
        $this->em()->getConnection()->executeStatement("UPDATE work_requests SET state = 'done' WHERE id = ?", [(string) $request->id]);
        self::assertSame(WorkRequestState::Open, $request->state);

        self::assertFalse($this->withdraw($request, WorkRequestState::Cancelled));
        self::assertSame([], $this->outboxPayloads());
    }

    public function test_an_unknown_request_changes_nothing(): void
    {
        $this->boot();
        $handler = $this->handler();

        self::assertFalse($handler(new WithdrawWorkRequestCommand(Uuid::v7(), WorkRequestState::Cancelled)));
    }

    public function test_a_request_does_not_withdraw_to_a_settled_state(): void
    {
        $this->boot();
        $request = $this->request('withdraw-wrong', WorkRequestState::Open);

        $this->expectException(\LogicException::class);

        $this->withdraw($request, WorkRequestState::Done);
    }

    /** Nothing in production calls the handler yet, so the compiled container holds none. */
    private function handler(): WithdrawWorkRequestHandler
    {
        return new WithdrawWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock(self::NOW),
            $this->service(Auditor::class),
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

    private function boot(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    private function request(string $name, WorkRequestState $state): WorkRequest
    {
        $em = $this->em();
        $project = $this->project($em, $this->user($em, $name.'@example.com'), 'Project '.substr(md5($name), 0, 8));
        $request = $this->seedWorkRequest($em, $project, state: $state);
        if (WorkRequestState::Claimed === $state) {
            $request->bridgeId = Uuid::v4();
            $request->claimToken = Uuid::v4();
            $request->leaseUntil = new \DateTimeImmutable('2026-10-01 12:35:00');
            $em->flush();
        }

        return $request;
    }

    private function withdraw(WorkRequest $request, WorkRequestState $state): bool
    {
        $handler = $this->handler();

        return $handler(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('A flushed request has an id.'), $state));
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
