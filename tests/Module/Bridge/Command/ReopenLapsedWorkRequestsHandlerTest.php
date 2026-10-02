<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ReopenLapsedWorkRequestsCommand;
use App\Module\Bridge\Command\ReopenLapsedWorkRequestsHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ReopenLapsedWorkRequestsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01 12:30:00';

    public function test_a_lapsed_claim_opens_again_and_tells_the_bridges(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-lapsed@example.com'), 'Reopen Lapsed');
        $lapsed = $this->claimed($project, '2026-10-01 12:29:59');
        $atTheEdge = $this->claimed($project, self::NOW);
        $live = $this->claimed($project, '2026-10-01 12:30:01');
        $this->seedWorkRequest($em, $project);

        self::assertSame(2, $this->handler()(new ReopenLapsedWorkRequestsCommand()));

        $em->clear();
        foreach ([$lapsed, $atTheEdge] as $request) {
            $stored = $em->find(WorkRequest::class, $request->id);
            self::assertSame(WorkRequestState::Open, $stored?->state);
            self::assertNull($stored->bridgeId);
            self::assertNull($stored->claimToken);
            self::assertNull($stored->leaseUntil);
        }
        $kept = $em->find(WorkRequest::class, $live->id);
        self::assertSame(WorkRequestState::Claimed, $kept?->state);
        self::assertSame('2026-10-01 12:30:01', $kept->leaseUntil?->format('Y-m-d H:i:s'));

        $payloads = $this->outboxPayloads();
        self::assertEqualsCanonicalizing([(string) $lapsed->id, (string) $atTheEdge->id], array_column($payloads, 'workRequestId'));
        self::assertSame(['open', 'open'], array_column($payloads, 'state'));
        self::assertSame([(string) $project->id, (string) $project->id], array_column($payloads, 'projectId'));
    }

    public function test_a_sweep_with_no_lapsed_claim_writes_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-quiet@example.com'), 'Reopen Quiet');
        $this->claimed($project, '2026-10-01 12:31:00');

        self::assertSame(0, $this->handler()(new ReopenLapsedWorkRequestsCommand()));

        self::assertSame([], $this->outboxPayloads());
    }

    private function claimed(Project $project, string $leaseUntil): WorkRequest
    {
        return $this->seedWorkRequest(
            $this->em(),
            $project,
            state: WorkRequestState::Claimed,
            bridgeId: Uuid::v4(),
            claimToken: Uuid::v4(),
            leaseUntil: new \DateTimeImmutable($leaseUntil),
        );
    }

    private function handler(): ReopenLapsedWorkRequestsHandler
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $outbox = self::getContainer()->get(OutboxWriter::class);
        self::assertInstanceOf(OutboxWriter::class, $outbox);

        return new ReopenLapsedWorkRequestsHandler(new WorkRequestRepository($registry), $outbox, $this->em(), new MockClock(self::NOW));
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
