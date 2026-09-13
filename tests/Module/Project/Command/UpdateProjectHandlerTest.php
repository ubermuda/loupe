<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\UpdateProjectCommand;
use App\Module\Project\Command\UpdateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\ProjectEventType;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
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
        $this->handler = new UpdateProjectHandler($projects, $this->em, $this->audit->auditor, $this->events());
    }

    public function test_an_updated_project_is_recorded_on_the_domain_channel(): void
    {
        $project = $this->project('update-project-audit@example.com', 'before');

        ($this->handler)(new UpdateProjectCommand($project, 'after', 'after.example.com', SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));

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

        ($this->handler)(new UpdateProjectCommand($project, 'lang', null, SearchLanguage::Italian, ProjectEventType::ACTOR_HUMAN));

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
            ($this->handler)(new UpdateProjectCommand($project, 'taken', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));
            self::fail('Expected DomainErrors for a colliding project name.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.name_taken'], $e->errors);
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_a_rename_stores_the_slug_of_the_new_name(): void
    {
        $project = $this->project('update-project-slug@example.com', 'Before Name');

        ($this->handler)(new UpdateProjectCommand($project, 'After Name', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));

        $stored = $this->em->getConnection()->fetchOne(
            'SELECT slug FROM projects WHERE id = :id',
            ['id' => (string) $project->id],
        );
        self::assertSame('after-name', $stored);
    }

    public function test_a_rename_to_a_name_whose_slug_is_empty_records_nothing(): void
    {
        $project = $this->project('update-project-empty-slug@example.com', 'Rocket');

        try {
            ($this->handler)(new UpdateProjectCommand($project, '🚀', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));
            self::fail('Expected DomainErrors for a name that gives an empty slug.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.slug_empty'], $e->errors);
        }

        self::assertSame('Rocket', $project->name);
        self::assertSame([], $this->audit->operations());
    }

    public function test_a_rename_to_a_slug_another_project_has_records_nothing(): void
    {
        $taken = $this->project('update-project-slug-taken@example.com', 'My App');
        $project = new Project($taken->owner, 'Other');
        $this->em->persist($project);
        $this->em->flush();

        try {
            ($this->handler)(new UpdateProjectCommand($project, 'my-app', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));
            self::fail('Expected DomainErrors for a name whose slug is taken.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.slug_taken'], $e->errors);
        }

        self::assertSame('other', $project->slug);
        self::assertSame([], $this->audit->operations());
    }

    public function test_an_update_keeps_a_suffixed_slug_while_another_project_holds_the_plain_one(): void
    {
        $project = $this->project('update-project-suffixed@example.com', 'Suffixed');
        $id = $project->id;
        self::assertNotNull($id);
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE projects SET slug = 'suffixed-2' WHERE id = :id", ['id' => (string) $id]);
        $this->em->persist(new Project($project->owner, 'SUFFIXED'));
        $this->em->flush();
        $this->em->clear();
        $loaded = $this->em->find(Project::class, $id);
        self::assertInstanceOf(Project::class, $loaded);

        ($this->handler)(new UpdateProjectCommand($loaded, 'Suffixed', 'new.example', SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));

        self::assertSame('suffixed-2', $connection->fetchOne('SELECT slug FROM projects WHERE id = :id', ['id' => (string) $id]));
    }

    public function test_an_update_fills_the_slug_an_older_image_left_empty(): void
    {
        $project = $this->project('update-project-null-slug@example.com', 'Left Empty');
        $id = $project->id;
        self::assertNotNull($id);
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE projects SET slug = NULL WHERE id = :id', ['id' => (string) $id]);
        $this->em->clear();
        $loaded = $this->em->find(Project::class, $id);
        self::assertInstanceOf(Project::class, $loaded);

        ($this->handler)(new UpdateProjectCommand($loaded, 'Left Empty', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));

        self::assertSame('left-empty', $connection->fetchOne('SELECT slug FROM projects WHERE id = :id', ['id' => (string) $id]));
    }

    public function test_an_update_that_keeps_the_name_repairs_a_stale_slug(): void
    {
        $project = $this->project('update-project-stale@example.com', 'Current Name');
        $id = $project->id;
        self::assertNotNull($id);
        $connection = $this->em->getConnection();
        // An image older than the slug column renamed the project and left the slug behind.
        $connection->executeStatement("UPDATE projects SET slug = 'old-name' WHERE id = :id", ['id' => (string) $id]);
        $this->em->clear();
        $loaded = $this->em->find(Project::class, $id);
        self::assertInstanceOf(Project::class, $loaded);

        ($this->handler)(new UpdateProjectCommand($loaded, 'Current Name', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));

        self::assertSame('current-name', $connection->fetchOne('SELECT slug FROM projects WHERE id = :id', ['id' => (string) $id]));
    }

    public function test_a_stale_slug_that_looks_suffixed_is_repaired_when_the_plain_slug_is_free(): void
    {
        $project = $this->project('update-project-stale-suffix@example.com', 'My App');
        $id = $project->id;
        self::assertNotNull($id);
        $connection = $this->em->getConnection();
        // An older image renamed "My App 2" to "My App" and left the slug behind.
        $connection->executeStatement("UPDATE projects SET slug = 'my-app-2' WHERE id = :id", ['id' => (string) $id]);
        $this->em->clear();
        $loaded = $this->em->find(Project::class, $id);
        self::assertInstanceOf(Project::class, $loaded);

        ($this->handler)(new UpdateProjectCommand($loaded, 'My App', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));

        self::assertSame('my-app', $connection->fetchOne('SELECT slug FROM projects WHERE id = :id', ['id' => (string) $id]));
    }

    public function test_repairing_a_stale_slug_to_a_taken_one_records_nothing(): void
    {
        $taken = $this->project('update-project-stale-taken@example.com', 'My App');
        $project = new Project($taken->owner, 'my-app-stale');
        $this->em->persist($project);
        $this->em->flush();
        $id = $project->id;
        self::assertNotNull($id);
        $connection = $this->em->getConnection();
        // An older image renamed "my-app-stale" to "my app" and left the slug behind.
        $connection->executeStatement("UPDATE projects SET name = 'my app' WHERE id = :id", ['id' => (string) $id]);
        $this->em->clear();
        $loaded = $this->em->find(Project::class, $id);
        self::assertInstanceOf(Project::class, $loaded);

        try {
            ($this->handler)(new UpdateProjectCommand($loaded, 'my app', 'new.example', SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));
            self::fail('Expected DomainErrors for a stale slug whose repair is taken.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.slug_taken'], $e->errors);
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_a_slug_taken_by_a_concurrent_rename_is_a_slug_error(): void
    {
        $project = $this->project('update-project-slug-race@example.com', 'Before');
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $racing = new UpdateProjectHandler($projects, new RivalBeforeFlush($this->em, [
            'id' => (string) Uuid::v7(),
            'owner_id' => (string) $project->owner->id,
            'name' => 'My App',
            'slug' => 'my-app',
            'created_at' => '2026-09-12 00:00:00',
        ]), $this->audit->auditor, $this->events());

        try {
            $racing(new UpdateProjectCommand($project, 'my-app', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));
            self::fail('Expected DomainErrors for a slug a concurrent rename took.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.slug_taken'], $e->errors);
        }
    }

    public function test_a_name_taken_by_a_concurrent_rename_is_a_name_error(): void
    {
        $project = $this->project('update-project-name-race@example.com', 'Before');
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $racing = new UpdateProjectHandler($projects, new RivalBeforeFlush($this->em, [
            'id' => (string) Uuid::v7(),
            'owner_id' => (string) $project->owner->id,
            'name' => 'After',
            'created_at' => '2026-09-12 00:00:00',
        ]), $this->audit->auditor, $this->events());

        try {
            $racing(new UpdateProjectCommand($project, 'After', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));
            self::fail('Expected DomainErrors for a name a concurrent rename took.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.name_taken'], $e->errors);
        }
    }

    public function test_a_rename_that_keeps_the_slug_is_allowed(): void
    {
        $project = $this->project('update-project-same-slug@example.com', 'My App');

        ($this->handler)(new UpdateProjectCommand($project, 'my app', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));

        self::assertSame('my app', $project->name);
        self::assertSame('my-app', $project->slug);
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(UpdateProjectHandler::class);
    }

    private function events(): EventDispatcherInterface
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        return $events;
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
