<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkRequestRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    private const string LEASE_UNTIL = '2026-10-01 12:05:00';

    public function test_a_claim_takes_an_open_request_once(): void
    {
        self::bootKernel();
        $em = $this->em();
        $request = $this->seedWorkRequest($em, $this->project($em, $this->user($em, 'claim-once@example.com'), 'Claim Once'));
        $id = $request->id ?? throw new \LogicException('A flushed request has an id.');
        $bridgeId = Uuid::v4();
        $token = Uuid::v4();
        $leaseUntil = new \DateTimeImmutable(self::LEASE_UNTIL);

        self::assertTrue($this->repository()->claim($id, $bridgeId, $token, $leaseUntil));
        self::assertFalse($this->repository()->claim($id, Uuid::v4(), Uuid::v4(), $leaseUntil));

        $em->clear();
        $stored = $em->find(WorkRequest::class, $id);
        self::assertInstanceOf(WorkRequest::class, $stored);
        self::assertSame(WorkRequestState::Claimed, $stored->state);
        self::assertSame((string) $bridgeId, (string) $stored->bridgeId);
        self::assertSame((string) $token, (string) $stored->claimToken);
        self::assertSame(self::LEASE_UNTIL, $stored->leaseUntil?->format('Y-m-d H:i:s'));
        self::assertSame(1, $stored->claims);
    }

    public function test_a_claim_of_a_settled_or_unknown_request_fails(): void
    {
        self::bootKernel();
        $em = $this->em();
        $request = $this->seedWorkRequest($em, $this->project($em, $this->user($em, 'claim-settled@example.com'), 'Claim Settled'), state: WorkRequestState::Cancelled);
        $leaseUntil = new \DateTimeImmutable(self::LEASE_UNTIL);

        self::assertFalse($this->repository()->claim($request->id ?? throw new \LogicException(), Uuid::v4(), Uuid::v4(), $leaseUntil));
        self::assertFalse($this->repository()->claim(Uuid::v7(), Uuid::v4(), Uuid::v4(), $leaseUntil));
    }

    /** The claim writes with native SQL, so the managed copy still reads open. */
    public function test_the_locked_read_refreshes_a_managed_request(): void
    {
        self::bootKernel();
        $em = $this->em();
        $request = $this->seedWorkRequest($em, $this->project($em, $this->user($em, 'claim-refresh@example.com'), 'Claim Refresh'));
        $id = $request->id ?? throw new \LogicException('A flushed request has an id.');
        $this->repository()->claim($id, Uuid::v4(), Uuid::v4(), new \DateTimeImmutable(self::LEASE_UNTIL));
        self::assertSame(WorkRequestState::Open, $request->state);

        $state = $em->wrapInTransaction(fn (): ?WorkRequestState => $this->repository()->findOneLocked($id)?->state);

        self::assertSame(WorkRequestState::Claimed, $state);
    }

    public function test_a_renewal_needs_the_bridge_and_the_token_of_the_claim(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'renew@example.com'), 'Renew');
        $bridgeId = Uuid::v4();
        $first = $this->claimed($project, $bridgeId, $firstToken = Uuid::v4());
        $second = $this->claimed($project, $bridgeId, Uuid::v4());
        $other = $this->claimed($project, Uuid::v4(), $otherToken = Uuid::v4());
        $settledToken = Uuid::v4();
        $settled = $this->claimed($project, $bridgeId, $settledToken);
        $em->getConnection()->executeStatement("UPDATE work_requests SET state = 'done' WHERE id = ?", [(string) $settled->id]);
        $later = new \DateTimeImmutable('2026-10-01 12:10:00');

        $renewed = $this->repository()->renewLeases($bridgeId, [
            [$this->idOf($first), $firstToken],
            [$this->idOf($second), Uuid::v4()],
            [$this->idOf($other), $otherToken],
            [$this->idOf($settled), $settledToken],
        ], $later);

        self::assertSame([(string) $first->id], $renewed);
        self::assertSame([], $this->repository()->renewLeases($bridgeId, [], $later));
        $leases = $em->getConnection()->fetchAllKeyValue('SELECT id, lease_until FROM work_requests');
        self::assertSame('2026-10-01 12:10:00', $leases[(string) $first->id]);
        self::assertSame(self::LEASE_UNTIL, $leases[(string) $second->id]);
        self::assertSame(self::LEASE_UNTIL, $leases[(string) $other->id]);
    }

    public function test_offers_are_the_open_requests_of_the_projects_a_bridge_can_run_oldest_first(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'offers@example.com');
        $followed = $this->project($em, $owner, 'Followed');
        $alsoFollowed = $this->project($em, $owner, 'Also Followed');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-01 '.$time);
        $newer = $this->seedWorkRequest($em, $followed, createdAt: $at('12:05:00'));
        $older = $this->seedWorkRequest($em, $alsoFollowed, createdAt: $at('12:00:00'));
        $interactive = $this->seedWorkRequest($em, $followed, capability: 'interactive', createdAt: $at('12:10:00'));
        $this->seedWorkRequest($em, $followed, capability: 'gpu', createdAt: $at('11:00:00'));
        $this->seedWorkRequest($em, $followed, state: WorkRequestState::Claimed, createdAt: $at('11:00:00'));
        $this->seedWorkRequest($em, $followed, state: WorkRequestState::Done, createdAt: $at('11:00:00'));
        $this->seedWorkRequest($em, $this->project($em, $owner, 'Not Followed'), createdAt: $at('11:00:00'));
        $projects = [$this->idOf($followed), $this->idOf($alsoFollowed)];

        self::assertSame([$older, $newer, $interactive], $this->repository()->findOpenOffers($projects, ['work-requests', 'interactive'], 10));
        self::assertSame([$older, $newer], $this->repository()->findOpenOffers($projects, [], 10));
        self::assertSame([$older], $this->repository()->findOpenOffers($projects, ['interactive'], 1));
        self::assertSame([], $this->repository()->findOpenOffers([], ['interactive'], 10));
    }

    public function test_the_sweep_reopens_the_claims_whose_lease_lapsed(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'lapse@example.com'), 'Lapse');
        $lapsed = $this->claimed($project, Uuid::v4(), Uuid::v4(), new \DateTimeImmutable('2026-10-01 12:00:00'));
        $held = $this->claimed($project, Uuid::v4(), Uuid::v4(), new \DateTimeImmutable('2026-10-01 12:00:01'));
        $open = $this->seedWorkRequest($em, $project);

        $reopened = $this->repository()->reopenLapsed(new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertSame([$lapsed], $reopened);
        self::assertSame(WorkRequestState::Open, $lapsed->state);
        self::assertNull($lapsed->bridgeId);
        self::assertNull($lapsed->claimToken);
        self::assertNull($lapsed->leaseUntil);
        self::assertSame(1, $lapsed->claims);
        $em->clear();
        self::assertSame(WorkRequestState::Claimed, $em->find(WorkRequest::class, $held->id)?->state);
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $open->id)?->state);
        self::assertSame([], $this->repository()->reopenLapsed(new \DateTimeImmutable('2026-10-01 12:00:00')));
    }

    public function test_has_live_reads_the_open_and_claimed_requests_of_a_card_and_kind(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'has-live@example.com'), 'Has Live');
        $open = $this->seedWorkRequest($em, $project);
        $claimed = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed);
        $done = $this->seedWorkRequest($em, $project, state: WorkRequestState::Done);

        self::assertTrue($this->repository()->hasLive($open->cardId, 'implement'));
        self::assertFalse($this->repository()->hasLive($open->cardId, 'design'));
        self::assertTrue($this->repository()->hasLive($claimed->cardId, 'implement'));
        self::assertFalse($this->repository()->hasLive($done->cardId, 'implement'));
    }

    /**
     * DAMA wraps the test in one transaction, and a failed statement aborts
     * it. The inserts run in a savepoint, and through DBAL, because a failed
     * flush closes the entity manager.
     */
    public function test_the_unique_index_allows_one_live_request_per_card_and_kind(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'unique@example.com'), 'Unique');
        $first = $this->seedWorkRequest($em, $project);
        $connection = $em->getConnection();
        $insert = static fn (string $kind) => $connection->insert('work_requests', [
            'id' => (string) Uuid::v7(),
            'project_id' => (string) $project->id,
            'card_id' => (string) $first->cardId,
            'card_number' => 7,
            'kind' => $kind,
            'rule_id' => 'implement-on-entry',
            'state' => 'open',
            'created_at' => '2026-10-01 12:00:00',
        ]);

        $connection->beginTransaction();
        try {
            $insert('implement');
            self::fail('Expected the unique index to refuse a second live request.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertStringContainsString(WorkRequest::LIVE_CARD_KIND_INDEX, $e->getMessage());
        } finally {
            $connection->rollBack();
        }

        $insert('design');
        $connection->executeStatement("UPDATE work_requests SET state = 'cancelled' WHERE id = ?", [(string) $first->id]);
        $insert('implement');

        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM work_requests'));
    }

    private function claimed(Project $project, Uuid $bridgeId, Uuid $token, \DateTimeImmutable $leaseUntil = new \DateTimeImmutable(self::LEASE_UNTIL)): WorkRequest
    {
        $request = $this->seedWorkRequest($this->em(), $project);
        $request->state = WorkRequestState::Claimed;
        $request->bridgeId = $bridgeId;
        $request->claimToken = $token;
        $request->leaseUntil = $leaseUntil;
        $request->claims = 1;
        $this->em()->flush();

        return $request;
    }

    private function idOf(Project|WorkRequest $entity): Uuid
    {
        return $entity->id ?? throw new \LogicException('A flushed entity has an id.');
    }

    private function repository(): WorkRequestRepository
    {
        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);

        return $repository;
    }
}
