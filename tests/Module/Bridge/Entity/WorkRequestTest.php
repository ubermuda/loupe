<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkRequestTest extends TestCase
{
    private const string NOW = '2026-10-01 12:00:00';

    public function test_a_new_request_is_open_and_unclaimed(): void
    {
        $request = $this->request();

        self::assertSame(WorkRequestState::Open, $request->state);
        self::assertNull($request->bridgeId);
        self::assertNull($request->claimToken);
        self::assertNull($request->leaseUntil);
        self::assertSame(0, $request->claims);
        self::assertNull($request->settledAt);
    }

    /** @return iterable<string, array{WorkRequestState, bool}> */
    public static function liveness(): iterable
    {
        yield 'open' => [WorkRequestState::Open, true];
        yield 'claimed' => [WorkRequestState::Claimed, true];
        yield 'done' => [WorkRequestState::Done, false];
        yield 'refused' => [WorkRequestState::Refused, false];
        yield 'expired' => [WorkRequestState::Expired, false];
        yield 'cancelled' => [WorkRequestState::Cancelled, false];
    }

    #[DataProvider('liveness')]
    public function test_only_open_and_claimed_are_live(WorkRequestState $state, bool $live): void
    {
        self::assertSame($live, $state->isLive());
    }

    public function test_a_claimed_request_settles_with_a_reason_code(): void
    {
        $request = $this->claimed();
        $now = new \DateTimeImmutable(self::NOW);

        self::assertTrue($request->settle(WorkRequestState::Refused, 'no-capacity', $now));

        self::assertSame(WorkRequestState::Refused, $request->state);
        self::assertSame('no-capacity', $request->reason);
        self::assertSame($now, $request->settledAt);
    }

    /** @return iterable<string, array{WorkRequestState}> */
    public static function notClaimed(): iterable
    {
        yield 'open' => [WorkRequestState::Open];
        yield 'done' => [WorkRequestState::Done];
        yield 'cancelled' => [WorkRequestState::Cancelled];
    }

    #[DataProvider('notClaimed')]
    public function test_a_request_that_is_not_claimed_does_not_settle(WorkRequestState $state): void
    {
        $request = $this->request();
        $request->state = $state;

        self::assertFalse($request->settle(WorkRequestState::Done, null, new \DateTimeImmutable(self::NOW)));

        self::assertSame($state, $request->state);
        self::assertNull($request->settledAt);
    }

    public function test_a_request_does_not_settle_to_a_live_or_withdrawn_state(): void
    {
        $this->expectException(\LogicException::class);

        $this->claimed()->settle(WorkRequestState::Cancelled, null, new \DateTimeImmutable(self::NOW));
    }

    public function test_a_reason_is_a_code_and_never_free_text(): void
    {
        $this->expectException(\LogicException::class);

        $this->claimed()->settle(WorkRequestState::Refused, 'The bridge is busy.', new \DateTimeImmutable(self::NOW));
    }

    public function test_an_open_request_withdraws(): void
    {
        $request = $this->request();
        $now = new \DateTimeImmutable(self::NOW);

        self::assertTrue($request->withdraw(WorkRequestState::Cancelled, $now));

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        self::assertSame($now, $request->settledAt);
    }

    public function test_a_claimed_request_withdraws_and_keeps_its_bridge(): void
    {
        $request = $this->claimed();

        self::assertTrue($request->withdraw(WorkRequestState::Expired, new \DateTimeImmutable(self::NOW)));

        self::assertSame(WorkRequestState::Expired, $request->state);
        self::assertNotNull($request->bridgeId);
    }

    public function test_a_settled_request_does_not_withdraw(): void
    {
        $request = $this->claimed();
        $request->settle(WorkRequestState::Done, null, new \DateTimeImmutable('2026-10-01 11:00:00'));

        self::assertFalse($request->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable(self::NOW)));

        self::assertSame(WorkRequestState::Done, $request->state);
        self::assertSame('2026-10-01 11:00:00', $request->settledAt?->format('Y-m-d H:i:s'));
    }

    public function test_a_request_does_not_withdraw_to_a_settled_state(): void
    {
        $this->expectException(\LogicException::class);

        $this->request()->withdraw(WorkRequestState::Done, new \DateTimeImmutable(self::NOW));
    }

    private function request(): WorkRequest
    {
        $project = new Project(new User('Riley Chen', 'riley@example.com', 'x'), 'Requests');

        return new WorkRequest($project, WorkSubject::CARD, Uuid::v7(), 7, 'implement', null, 'implement-on-entry', new \DateTimeImmutable(self::NOW));
    }

    private function claimed(): WorkRequest
    {
        $request = $this->request();
        $request->state = WorkRequestState::Claimed;
        $request->bridgeId = Uuid::v4();
        $request->claimToken = Uuid::v4();
        $request->leaseUntil = new \DateTimeImmutable('2026-10-01 12:05:00');
        $request->claims = 1;

        return $request;
    }
}
