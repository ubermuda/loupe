<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
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
        $project = new Project($owner, $name);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function agentToken(EntityManagerInterface $em, User $owner): string
    {
        [$token, $raw] = ApiToken::issue($owner, 'bridge', ApiTokenScope::Agent);
        $em->persist($token);
        $em->flush();

        return $raw;
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
    ): WorkerRun {
        $run = new WorkerRun(
            project: $project,
            bridgeId: $bridgeId ?? Uuid::v7(),
            sessionId: Uuid::v4(),
            cardId: $cardId ?? Uuid::v7(),
            cardNumber: $cardNumber,
            ruleName: $ruleName,
            startedAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
            endedAt: new \DateTimeImmutable('2026-01-01 10:05:00'),
            exitCode: $exitCode,
            failureReason: $failureReason,
            output: $output,
            receivedAt: $receivedAt,
        );
        $em->persist($run);
        $em->flush();
        // What the report endpoint does after it writes the row. A run seeded
        // without this carries a null vector and no search can reach it.
        $this->searchIndexer()->index($run);

        return $run;
    }

    private function searchIndexer(): WorkerRunSearchIndexer
    {
        $indexer = static::getContainer()->get(WorkerRunSearchIndexer::class);
        self::assertInstanceOf(WorkerRunSearchIndexer::class, $indexer);

        return $indexer;
    }
}
