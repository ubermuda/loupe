<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Entity\ForgeRepositoryHealth;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class ForgeRepositoryTest extends TestCase
{
    private const string NOW = '2026-09-23 12:00:00';

    public function test_a_repository_with_no_acceptance_is_waiting(): void
    {
        self::assertSame(ForgeRepositoryHealth::Waiting, $this->repository(null)->health(new \DateTimeImmutable(self::NOW)));
    }

    public function test_a_repository_accepted_exactly_thirty_days_ago_is_working(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame(ForgeRepositoryHealth::Working, $this->repository($now->modify('-30 days'))->health($now));
    }

    public function test_a_repository_accepted_over_thirty_days_ago_is_quiet(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame(ForgeRepositoryHealth::Quiet, $this->repository($now->modify('-30 days -1 second'))->health($now));
    }

    private function repository(?\DateTimeImmutable $lastAcceptedAt): ForgeRepository
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed'), 'widgets');
        $repository = new ForgeRepository($project, 'github', '42', 'acme/widgets', ForgeRepositorySource::Hook);
        $repository->lastAcceptedAt = $lastAcceptedAt;

        return $repository;
    }
}
