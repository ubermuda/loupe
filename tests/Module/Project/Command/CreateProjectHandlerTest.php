<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateProjectHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreateProjectHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->handler = new CreateProjectHandler($projects, $this->em, $this->audit->auditor);
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

    public function test_a_created_project_is_recorded_on_the_domain_channel(): void
    {
        $owner = $this->user('create-project-audit@example.com');

        $project = ($this->handler)(new CreateProjectCommand($owner, 'audited', null));

        $record = $this->audit->record('project.created');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('project', $record->subject->type);
        self::assertSame((string) $project->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'ownerId' => (string) $owner->id,
        ], $record->context);

        self::assertSame(['project.created'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_a_refused_create_records_nothing(): void
    {
        $owner = $this->user('create-project-audit-dup@example.com');
        ($this->handler)(new CreateProjectCommand($owner, 'taken', null));

        try {
            ($this->handler)(new CreateProjectCommand($owner, 'taken', null));
            self::fail('Expected DomainErrors for a duplicate project name.');
        } catch (DomainErrors) {
        }

        self::assertSame(['project.created'], $this->audit->operations());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(CreateProjectHandler::class);
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
