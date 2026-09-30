<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeCommandRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_find_pending_for_answers_the_live_commands_of_one_bridge_oldest_first(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'command-repo-pending@example.com');
        $project = $this->project($em, $owner, 'Pending Commands');
        $bridgeId = Uuid::v4();
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-09-29 '.$time);

        $newer = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), requestedAt: $at('11:55:00'));
        $older = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), requestedAt: $at('11:50:00'));
        // Past its expiry, and not swept yet.
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), requestedAt: $at('11:00:00'), expiresAt: $at('12:00:00'));
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), state: BridgeCommandState::Done, requestedAt: $at('11:45:00'));
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: Uuid::v4()), requestedAt: $at('11:45:00'));
        // Another account under the same bridge id.
        $other = $this->user($em, 'command-repo-pending-other@example.com');
        $this->seedCommand($em, $this->seedRun($em, $this->project($em, $other, 'Other Account'), bridgeId: $bridgeId), requestedAt: $at('11:45:00'));

        $pending = $this->repository()->findPendingFor($owner, $bridgeId, $at('12:00:00'));

        self::assertSame([$older, $newer], $pending);
    }

    public function test_the_locked_read_answers_the_command_of_that_owner_and_bridge_only(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'command-repo-one@example.com');
        $run = $this->seedRun($em, $this->project($em, $owner, 'One Command'), bridgeId: Uuid::v4());
        $command = $this->seedCommand($em, $run);
        $id = $command->id ?? throw new \LogicException('A flushed command has an id.');
        $bridgeId = $run->bridgeId ?? throw new \LogicException('The run has a bridge.');

        $other = $this->user($em, 'command-repo-one-other@example.com');
        $em->wrapInTransaction(function () use ($command, $owner, $other, $bridgeId, $id): void {
            self::assertSame($command, $this->repository()->findOneForBridgeLocked($owner, $bridgeId, $id));
            self::assertNull($this->repository()->findOneForBridgeLocked($owner, Uuid::v4(), $id));
            self::assertNull($this->repository()->findOneForBridgeLocked($other, $bridgeId, $id));
        });
    }

    /** The expiry sweep writes by bulk update, so a managed copy would still read pending. */
    public function test_the_locked_read_refreshes_a_managed_command(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'command-repo-refresh@example.com');
        $command = $this->seedCommand($em, $this->seedRun($em, $this->project($em, $owner, 'Refresh Command'), bridgeId: Uuid::v4()));
        $id = $command->id ?? throw new \LogicException('A flushed command has an id.');
        $this->repository()->expireDue(new \DateTimeImmutable('2027-01-01'));

        $state = $em->wrapInTransaction(fn (): ?BridgeCommandState => $this->repository()->findOneForBridgeLocked($owner, $command->bridgeId, $id)?->state);

        self::assertSame(BridgeCommandState::Expired, $state);
    }

    public function test_has_pending_for_run_reads_the_state_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'command-repo-has@example.com'), 'Has Pending');
        $free = $this->seedRun($em, $project, bridgeId: Uuid::v4());
        $settled = $this->seedRun($em, $project, bridgeId: Uuid::v4());
        $stale = $this->seedRun($em, $project, bridgeId: Uuid::v4());
        $this->seedCommand($em, $settled, state: BridgeCommandState::Refused);
        $this->seedCommand($em, $stale, expiresAt: new \DateTimeImmutable('2000-01-01'));

        self::assertFalse($this->repository()->hasPendingForRun($free));
        self::assertFalse($this->repository()->hasPendingForRun($settled));
        self::assertTrue($this->repository()->hasPendingForRun($stale));
    }

    public function test_expire_due_moves_only_the_pending_commands_past_their_expiry(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'command-repo-expire@example.com'), 'Expire Commands');
        $now = new \DateTimeImmutable('2026-09-29 12:00:00');
        $due = $this->seedCommand($em, $this->bridgeRun($project), expiresAt: $now);
        $live = $this->seedCommand($em, $this->bridgeRun($project), expiresAt: $now->modify('+1 second'));
        $done = $this->seedCommand($em, $this->bridgeRun($project), state: BridgeCommandState::Done, expiresAt: $now->modify('-1 hour'));

        self::assertSame(1, $this->repository()->expireDue($now));

        $em->clear();
        $reloaded = static fn (BridgeCommand $command): BridgeCommand => $em->find(BridgeCommand::class, $command->id) ?? throw new \LogicException('The command exists.');
        self::assertSame(BridgeCommandState::Expired, $reloaded($due)->state);
        self::assertSame('2026-09-29 12:00:00', $reloaded($due)->settledAt?->format('Y-m-d H:i:s'));
        self::assertSame(BridgeCommandState::Pending, $reloaded($live)->state);
        self::assertNull($reloaded($live)->settledAt);
        self::assertSame(BridgeCommandState::Done, $reloaded($done)->state);
        self::assertNull($reloaded($done)->settledAt);
    }

    private function bridgeRun(Project $project): WorkerRun
    {
        return $this->seedRun($this->em(), $project, bridgeId: Uuid::v4());
    }

    private function repository(): BridgeCommandRepository
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new BridgeCommandRepository($registry);
    }
}
