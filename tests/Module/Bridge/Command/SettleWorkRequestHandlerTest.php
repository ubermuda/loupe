<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\SettleWorkRequestCommand;
use App\Module\Bridge\Command\SettleWorkRequestHandler;
use App\Module\Bridge\Command\SettleWorkRequestResult;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Bridge\WorkSubject\RecordingWorkSubjectHandler;
use App\Tests\Support\DispatchedEvents;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class SettleWorkRequestHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    public function test_a_settlement_is_announced_once(): void
    {
        [$owner, $request] = $this->claimed('settle-announced');
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);
        $depth = $this->em()->getConnection()->getTransactionNestingLevel();

        self::assertTrue($this->settle($owner, $request, WorkRequestState::Refused)->settled);
        self::assertFalse($this->settle($owner, $request, WorkRequestState::Refused)->settled);

        self::assertCount(1, $changes->events());
        self::assertSame((string) $request->id, (string) $changes->events()[0]->workRequestId);
        self::assertSame(WorkRequestState::Refused, $changes->events()[0]->state);
        self::assertSame([$depth], $changes->transactionDepths());
    }

    /** A withdrawal keeps the bridge and the token, so only the state tells the holder it lost. */
    public function test_a_withdrawn_request_is_a_lost_claim(): void
    {
        [$owner, $request] = $this->claimed('settle-withdrawn');
        $request->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable(self::NOW));
        $this->em()->flush();
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $result = $this->settle($owner, $request, WorkRequestState::Done);
        self::assertSame([], $changes->events());

        self::assertSame(WorkRequestRefusal::ClaimLost, $result->refusal);
        $this->em()->clear();
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    /** The row is read fresh under the lock, so a reopen that bypassed the ORM is seen. */
    public function test_a_claim_reopened_behind_the_managed_copy_is_lost(): void
    {
        [$owner, $request] = $this->claimed('settle-fresh');
        $this->em()->getConnection()->executeStatement(
            "UPDATE work_requests SET state = 'open', bridge_id = NULL, claim_token = NULL, lease_until = NULL WHERE id = ?",
            [(string) $request->id],
        );

        $result = $this->settle($owner, $request, WorkRequestState::Done);

        self::assertSame(WorkRequestRefusal::ClaimLost, $result->refusal);
    }

    public function test_a_request_does_not_settle_to_a_state_a_bridge_never_gives(): void
    {
        [$owner, $request] = $this->claimed('settle-wrong');

        $this->expectException(\LogicException::class);

        $this->settle($owner, $request, WorkRequestState::Expired);
    }

    public function test_a_settlement_tells_the_handler_of_its_subject(): void
    {
        $subjects = new RecordingWorkSubjectHandler();
        [$owner, $request] = $this->claimed('settle-subject', new WorkSubject(RecordingWorkSubjectHandler::TYPE, Uuid::v7()));

        self::assertTrue($this->settle($owner, $request, WorkRequestState::Refused, $subjects)->settled);
        self::assertFalse($this->settle($owner, $request, WorkRequestState::Refused, $subjects)->settled);

        self::assertCount(1, $subjects->settled);
        self::assertSame((string) $request->id, (string) $subjects->settled[0]->id);
        self::assertSame(WorkRequestState::Refused, $subjects->settled[0]->state);
        self::assertSame([], $subjects->expired);
    }

    public function test_a_settlement_on_a_card_tells_no_subject_handler(): void
    {
        $subjects = new RecordingWorkSubjectHandler();
        [$owner, $request] = $this->claimed('settle-card');

        self::assertTrue($this->settle($owner, $request, WorkRequestState::Done, $subjects)->settled);

        self::assertSame([], $subjects->settled);
    }

    /** @return array{User, WorkRequest} */
    private function claimed(string $name, ?WorkSubject $subject = null): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $request = $this->seedWorkRequest($em, $this->project($em, $owner, 'Project '.$name), state: WorkRequestState::Claimed, subject: $subject);
        $request->bridgeId = Uuid::v4();
        $request->claimToken = Uuid::v4();
        $request->leaseUntil = new \DateTimeImmutable('2026-10-01 12:32:00');
        $em->flush();

        return [$owner, $request];
    }

    private function settle(User $owner, WorkRequest $request, WorkRequestState $state, ?RecordingWorkSubjectHandler $subjects = null): SettleWorkRequestResult
    {
        $handler = new SettleWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock(self::NOW),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            new WorkSubjectHandlers(null === $subjects ? [] : [$subjects]),
        );

        return $handler(new SettleWorkRequestCommand(
            owner: $owner,
            bridgeId: $request->bridgeId ?? throw new \LogicException('A claimed request has a bridge.'),
            workRequestId: $request->id ?? throw new \LogicException('A flushed request has an id.'),
            claimToken: $request->claimToken ?? throw new \LogicException('A claimed request has a token.'),
            state: $state,
            reason: null,
        ));
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
}
