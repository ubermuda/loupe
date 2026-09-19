<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\ProjectExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ProjectExporterTest extends TestCase
{
    public function test_exports_owned_projects(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($user, 'My project', 'example.com', description: 'Review the customer portal.');
        $project->forwardsToAgent = true;
        $project->allowedOrigins = ['https://staging.example.com'];

        /** @var ProjectRepository&Stub $repo */
        $repo = $this->createStub(ProjectRepository::class);
        $repo->method('findByOwner')->willReturn([$project]);

        $rows = iterator_to_array(new ProjectExporter($repo)->export($user));

        self::assertCount(1, $rows);
        self::assertSame('My project', $rows[0]['name']);
        self::assertSame('Review the customer portal.', $rows[0]['description']);
        self::assertSame('example.com', $rows[0]['domain']);
        self::assertSame('english', $rows[0]['searchLanguage']);
        self::assertTrue($rows[0]['forwardsToAgent']);
        self::assertSame(['https://staging.example.com'], $rows[0]['allowedOrigins']);
        self::assertArrayHasKey('createdAt', $rows[0]);
        self::assertSame('projects.json', new ProjectExporter($repo)->filename());
    }
}
