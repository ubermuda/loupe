<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\WizardState;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class WizardStateTest extends TestCase
{
    private function user(): User
    {
        return new User('Wiz State', 'wizstate@example.com');
    }

    public function test_is_completed_reflects_wizard_completed_at(): void
    {
        $projects = $this->createStub(ProjectRepository::class);
        $state = new WizardState($projects);

        $fresh = $this->user();
        self::assertFalse($state->isCompleted($fresh));

        $done = $this->user();
        $done->wizardCompletedAt = new \DateTimeImmutable();
        self::assertTrue($state->isCompleted($done));
    }

    public function test_first_project_returns_the_repositorys_oldest_project(): void
    {
        $user = $this->user();
        $project = new Project($user, 'first');

        /** @var ProjectRepository&Stub $projects */
        $projects = $this->createStub(ProjectRepository::class);
        $projects->method('findOldestByOwner')->willReturn($project);

        $state = new WizardState($projects);

        self::assertSame($project, $state->firstProject($user));
    }

    public function test_first_project_returns_null_when_the_user_owns_none(): void
    {
        $user = $this->user();

        /** @var ProjectRepository&Stub $projects */
        $projects = $this->createStub(ProjectRepository::class);
        $projects->method('findOldestByOwner')->willReturn(null);

        $state = new WizardState($projects);

        self::assertNull($state->firstProject($user));
    }
}
