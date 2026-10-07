<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;

/** Fixtures the Readiness module's database tests share. */
trait ReadinessScenario
{
    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'Riley Chen', email: $email, password: 'hashed');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function project(User $owner, string $name): Project
    {
        $project = new Project($owner, $name);
        $this->em()->persist($project);
        $this->em()->flush();

        return $project;
    }

    private function reload(Project $project): Project
    {
        $this->em()->clear();

        return $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');
    }
}
