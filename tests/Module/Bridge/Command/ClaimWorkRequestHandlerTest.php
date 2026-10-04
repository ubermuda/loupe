<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ClaimWorkRequestCommand;
use App\Module\Bridge\Command\ClaimWorkRequestHandler;
use App\Module\Bridge\Command\ClaimWorkRequestResult;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\DispatchedEvents;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class ClaimWorkRequestHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    public function test_a_claim_counts_and_leases_from_the_clock(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-ok', WorkRequestState::Open);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $result = $this->claim($owner, $bridge, $request);

        self::assertNull($result->refusal);
        self::assertSame(WorkRequestState::Claimed, $result->request?->state);
        self::assertSame(1, $result->request->claims);
        self::assertSame('2026-10-01T12:32:00+00:00', $result->request->leaseUntil?->format(\DateTimeInterface::ATOM));
        self::assertNotNull($result->request->claimToken);

        self::assertCount(1, $changes->events());
        self::assertSame((string) $request->id, (string) $changes->events()[0]->workRequestId);
        self::assertSame((string) $request->project->id, (string) $changes->events()[0]->projectId);
        self::assertSame(WorkRequestState::Claimed, $changes->events()[0]->state);
    }

    /**
     * The test transaction holds the heartbeat lock of the bridge after the
     * claim, so a heartbeat cannot change the bridge between the checks and the claim.
     */
    public function test_a_claim_takes_the_heartbeat_lock_of_the_bridge(): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-lock', WorkRequestState::Open);
        $this->claim($owner, $bridge, $request);
        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());

        try {
            self::assertFalse((bool) $other->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtext(?))',
                ['bridge:'.$owner->id?->toRfc4122().':'.$bridge->id->toRfc4122()],
            ));
        } finally {
            $other->close();
        }
    }

    /** @return iterable<string, array{WorkRequestState}> */
    public static function settledStates(): iterable
    {
        yield 'done' => [WorkRequestState::Done];
        yield 'refused' => [WorkRequestState::Refused];
        yield 'cancelled' => [WorkRequestState::Cancelled];
        yield 'expired' => [WorkRequestState::Expired];
    }

    #[DataProvider('settledStates')]
    public function test_a_request_that_is_no_longer_open_is_not_claimed(WorkRequestState $state): void
    {
        [$owner, $bridge, $request] = $this->scenario('claim-handler-settled', $state);
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);

        $result = $this->claim($owner, $bridge, $request);
        self::assertSame([], $changes->events());

        self::assertNull($result->request);
        self::assertSame(WorkRequestRefusal::AlreadyClaimed, $result->refusal);
        $this->em()->clear();
        self::assertSame($state, $this->em()->find(WorkRequest::class, $request->id)?->state);
    }

    /** @return array{User, Bridge, WorkRequest} */
    private function scenario(string $name, WorkRequestState $state): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.$name);
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id?->toRfc4122()]);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $em->flush();

        return [$owner, $bridge, $this->seedWorkRequest($em, $project, state: $state)];
    }

    private function claim(User $owner, Bridge $bridge, WorkRequest $request): ClaimWorkRequestResult
    {
        $handler = self::getContainer()->get(ClaimWorkRequestHandler::class);
        self::assertInstanceOf(ClaimWorkRequestHandler::class, $handler);

        return $handler(new ClaimWorkRequestCommand($owner, $bridge->id, $request->id ?? throw new \LogicException('A flushed request has an id.')));
    }
}
