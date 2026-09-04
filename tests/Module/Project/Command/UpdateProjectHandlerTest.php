<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\UpdateProjectCommand;
use App\Module\Project\Command\UpdateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class UpdateProjectHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UpdateProjectHandler $handler;
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
        $this->handler = new UpdateProjectHandler($projects, $this->em, $this->audit->auditor);
    }

    public function test_an_updated_project_is_recorded_on_the_domain_channel(): void
    {
        $project = $this->project('update-project-audit@example.com', 'before');

        ($this->handler)(new UpdateProjectCommand($project, 'after', 'after.example.com', SearchLanguage::English));

        self::assertSame('after', $project->name);
        self::assertSame('after.example.com', $project->domain);

        $record = $this->audit->record('project.updated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('project', $record->subject->type);
        self::assertSame((string) $project->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'ownerId' => (string) $project->owner->id,
        ], $record->context);

        self::assertSame(['project.updated'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_a_changed_search_language_is_stored(): void
    {
        $project = $this->project('update-project-language@example.com', 'lang');
        self::assertSame(SearchLanguage::English, $project->searchLanguage);

        ($this->handler)(new UpdateProjectCommand($project, 'lang', null, SearchLanguage::Italian));

        $id = $project->id;
        self::assertNotNull($id);
        $this->em->clear();
        $fresh = $this->em->find(Project::class, $id);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertSame(SearchLanguage::Italian, $fresh->searchLanguage);
    }

    public function test_a_name_collision_records_nothing(): void
    {
        $taken = $this->project('update-project-collision@example.com', 'taken');
        $project = new Project($taken->owner, 'mine');
        $this->em->persist($project);
        $this->em->flush();

        try {
            ($this->handler)(new UpdateProjectCommand($project, 'taken', null, SearchLanguage::English));
            self::fail('Expected DomainErrors for a colliding project name.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.name_taken'], $e->errors);
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(UpdateProjectHandler::class);
    }

    /** @param non-empty-string $email */
    private function project(string $email, string $name): Project
    {
        $owner = new User(fullName: 'U', email: $email, password: 'x');
        $project = new Project($owner, $name);
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
