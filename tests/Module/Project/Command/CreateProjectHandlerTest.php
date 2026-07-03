<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateProjectHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreateProjectHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $this->handler = new CreateProjectHandler($projects, $this->em, new NullLogger());
    }

    public function test_creates_project_with_domain(): void
    {
        $owner = $this->user('create-project-a@example.com');

        $project = ($this->handler)(new CreateProjectCommand($owner, 'my-app', 'my-app.example.com'));

        self::assertNotNull($project->id);
        self::assertSame($owner, $project->owner);
        self::assertSame('my-app', $project->name);
        self::assertSame('my-app.example.com', $project->domain);
        self::assertNull($project->widgetToken);
        self::assertNull($project->mcpToken);
    }

    public function test_creates_project_without_domain(): void
    {
        $owner = $this->user('create-project-b@example.com');

        $project = ($this->handler)(new CreateProjectCommand($owner, 'domainless', null));

        self::assertNotNull($project->id);
        self::assertNull($project->domain);
    }

    public function test_duplicate_name_for_same_owner_is_a_domain_error(): void
    {
        $owner = $this->user('create-project-c@example.com');
        ($this->handler)(new CreateProjectCommand($owner, 'dup', null));

        try {
            ($this->handler)(new CreateProjectCommand($owner, 'dup', null));
            self::fail('Expected DomainErrors for a duplicate project name.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.name_taken'], $e->errors);
        }
    }

    public function test_same_name_for_different_owner_is_allowed(): void
    {
        $a = $this->user('create-project-d@example.com');
        $b = $this->user('create-project-e@example.com');
        ($this->handler)(new CreateProjectCommand($a, 'shared-name', null));

        $project = ($this->handler)(new CreateProjectCommand($b, 'shared-name', null));

        self::assertNotNull($project->id);
        self::assertSame($b, $project->owner);
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
