<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\SettleWorkRequestCommand;
use App\Module\Bridge\Command\SettleWorkRequestHandler;
use App\Module\Bridge\Command\SettleWorkRequestResult;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class SettleWorkRequestHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    /** A withdrawal keeps the bridge and the token, so only the state tells the holder it lost. */
    public function test_a_withdrawn_request_is_a_lost_claim(): void
    {
        [$owner, $request] = $this->claimed('settle-withdrawn');
        $request->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable(self::NOW));
        $this->em()->flush();

        $result = $this->settle($owner, $request, WorkRequestState::Done);

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

    /** @return array{User, WorkRequest} */
    private function claimed(string $name): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $request = $this->seedWorkRequest($em, $this->project($em, $owner, 'Project '.$name), state: WorkRequestState::Claimed);
        $request->bridgeId = Uuid::v4();
        $request->claimToken = Uuid::v4();
        $request->leaseUntil = new \DateTimeImmutable('2026-10-01 12:32:00');
        $em->flush();

        return [$owner, $request];
    }

    private function settle(User $owner, WorkRequest $request, WorkRequestState $state): SettleWorkRequestResult
    {
        $handler = self::getContainer()->get(SettleWorkRequestHandler::class);
        self::assertInstanceOf(SettleWorkRequestHandler::class, $handler);

        return $handler(new SettleWorkRequestCommand(
            owner: $owner,
            bridgeId: $request->bridgeId ?? throw new \LogicException('A claimed request has a bridge.'),
            workRequestId: $request->id ?? throw new \LogicException('A flushed request has an id.'),
            claimToken: $request->claimToken ?? throw new \LogicException('A claimed request has a token.'),
            state: $state,
            reason: null,
        ));
    }
}
