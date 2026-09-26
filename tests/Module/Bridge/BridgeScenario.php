<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/** Fixtures the Bridge module's database tests share. */
trait BridgeScenario
{
    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Riley Chen', email: $email, password: 'hashed');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function project(EntityManagerInterface $em, User $owner, string $name): Project
    {
        $project = new Project(AgentCredential::managed($em, $owner, $owner->id), $name);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function agentToken(KernelBrowser $browser, User $owner): string
    {
        return AgentCredential::agentToken(static::getContainer(), $owner);
    }

    private function seedRun(
        EntityManagerInterface $em,
        Project $project,
        \DateTimeImmutable $receivedAt = new \DateTimeImmutable(),
        int $cardNumber = 1,
        ?int $exitCode = 0,
        ?string $failureReason = null,
        string $output = 'worker output',
        string $ruleName = 'plan',
        ?Uuid $bridgeId = null,
        ?Uuid $cardId = null,
        ?WorkerRunState $state = null,
        ?Uuid $runKey = null,
        ?bool $hasResult = null,
        WorkerRunKind $kind = WorkerRunKind::Worker,
    ): WorkerRun {
        $run = new WorkerRun(
            project: AgentCredential::managed($em, $project, $project->id),
            bridgeId: WorkerRunKind::Interactive === $kind ? $bridgeId : $bridgeId ?? Uuid::v7(),
            cardId: $cardId ?? Uuid::v7(),
            cardNumber: $cardNumber,
            ruleName: $ruleName,
            state: $state ?? WorkerRunState::fromOutcome($exitCode, $hasResult),
            runKey: $runKey,
            sessionId: Uuid::v4(),
            startedAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
            endedAt: new \DateTimeImmutable('2026-01-01 10:05:00'),
            exitCode: $exitCode,
            hasResult: $hasResult,
            failureReason: $failureReason,
            output: $output,
            receivedAt: $receivedAt,
            kind: $kind,
        );
        $em->persist($run);
        $em->flush();
        // What the report endpoint does after it writes the row. A run seeded
        // without this carries a null vector and no search can reach it.
        $this->searchIndexer()->index($run);

        return $run;
    }

    private function seedUsage(EntityManagerInterface $em, WorkerRun $run, string $model = 'claude-opus-5-5'): WorkerRunUsage
    {
        $usage = new WorkerRunUsage($run, $run->project, $run->cardId, $run->ruleName, $model, WorkerRunUsageSource::Reported, 100, 20, 300, 40, '0.012345');
        $run->usageSource = WorkerRunUsageSource::Reported;
        $em->persist($usage);
        $em->flush();

        return $usage;
    }

    private function countUsage(EntityManagerInterface $em): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_usage');
    }

    /** @param list<string> $projects */
    private function seedBridge(
        EntityManagerInterface $em,
        User $owner,
        ?Uuid $id = null,
        array $projects = [],
        string $cliVersion = 'b4e39aa7',
        \DateTimeImmutable $lastSeenAt = new \DateTimeImmutable(),
    ): Bridge {
        $bridge = new Bridge(AgentCredential::managed($em, $owner, $owner->id), $id ?? Uuid::v4(), $projects, $cliVersion, $lastSeenAt);
        $em->persist($bridge);
        $em->flush();

        return $bridge;
    }

    private function searchIndexer(): WorkerRunSearchIndexer
    {
        $indexer = static::getContainer()->get(WorkerRunSearchIndexer::class);
        self::assertInstanceOf(WorkerRunSearchIndexer::class, $indexer);

        return $indexer;
    }
}
