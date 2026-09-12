<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\EventListener;

use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardReporter;
use App\Module\Project\Command\UpdateProjectCommand;
use App\Module\Project\Command\UpdateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectRenamed;
use App\Module\Project\ProjectEventType;
use App\Module\Project\Repository\ProjectRepository;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Project\Command\RivalBeforeFlush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class WriteOutboxEventOnProjectRenamedTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UpdateProjectHandler $updateProject;
    private OutboxEventRepository $outbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $updateProject = self::getContainer()->get(UpdateProjectHandler::class);
        self::assertInstanceOf(UpdateProjectHandler::class, $updateProject);
        $this->updateProject = $updateProject;

        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);
        $this->outbox = $outbox;
    }

    /**
     * The keys and the value types below are what the bridge decodes, so a
     * rename that drifts from them drops the event with nothing in any log.
     */
    public function test_a_rename_writes_the_payload_the_reader_decodes(): void
    {
        $project = $this->project('Before Name');

        $this->rename($project, 'After Name');

        $row = $this->onlyRow($project);
        self::assertSame([
            'type' => 'project.renamed',
            'subject' => ['type' => 'project', 'id' => (string) $project->id],
            'projectId' => (string) $project->id,
            'fromSlug' => 'before-name',
            'toSlug' => 'after-name',
            'actor' => 'human',
        ], $this->decode($row));
    }

    public function test_the_row_carries_the_type_and_the_topic(): void
    {
        $project = $this->project('Routable');

        $this->rename($project, 'Routed');

        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        $row = $this->onlyRow($project);
        self::assertSame('project.renamed', $row->type);
        self::assertNotNull($project->id);
        self::assertSame($topics->forProject($project->id), $row->topic);
        self::assertNull($row->publishedAt);
    }

    public function test_the_human_actor_is_the_value_the_board_writes(): void
    {
        self::assertSame(CardReporter::Human->value, ProjectEventType::ACTOR_HUMAN);
    }

    /** The rename proves the listener runs, so the unchanged count proves the second save wrote nothing. */
    public function test_a_save_that_keeps_the_slug_writes_no_row(): void
    {
        $project = $this->project('My App');
        $this->rename($project, 'Other App');
        self::assertCount(1, $this->rows($project));

        $this->rename($project, 'other app', 'new.example');

        self::assertSame('other app', $project->name);
        self::assertCount(1, $this->rows($project));
    }

    public function test_a_suffixed_slug_repaired_to_the_plain_slug_writes_a_row(): void
    {
        $project = $this->project('My App');
        $id = $project->id;
        self::assertNotNull($id);
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE projects SET slug = 'my-app-2' WHERE id = :id", ['id' => (string) $id]);
        $this->em->clear();
        $loaded = $this->em->find(Project::class, $id);
        self::assertInstanceOf(Project::class, $loaded);

        $this->rename($loaded, 'My App');

        $payload = $this->decode($this->onlyRow($loaded));
        self::assertSame('my-app-2', $payload['fromSlug']);
        self::assertSame('my-app', $payload['toSlug']);
    }

    /** No rule file can name a project that had no slug, so its first slug is no rename. */
    public function test_a_first_slug_for_a_row_an_older_image_wrote_writes_no_row(): void
    {
        $project = $this->project('Left Empty');
        $id = $project->id;
        self::assertNotNull($id);
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE projects SET slug = NULL WHERE id = :id', ['id' => (string) $id]);
        $this->em->clear();
        $loaded = $this->em->find(Project::class, $id);
        self::assertInstanceOf(Project::class, $loaded);

        $this->rename($loaded, 'Left Empty');

        self::assertSame('left-empty', $connection->fetchOne('SELECT slug FROM projects WHERE id = :id', ['id' => (string) $id]));
        self::assertSame([], $this->rows($loaded));

        // Without this the empty list above also passes when the listener never runs.
        $this->rename($loaded, 'Filled');
        self::assertSame('left-empty', $this->decode($this->onlyRow($loaded))['fromSlug']);
    }

    public function test_a_refused_rename_writes_no_row(): void
    {
        $taken = $this->project('Taken');
        $project = new Project($taken->owner, 'Mine');
        $this->em->persist($project);
        $this->em->flush();
        $this->rename($project, 'Ours');
        self::assertCount(1, $this->rows($project));

        try {
            $this->rename($project, 'taken');
            self::fail('Expected DomainErrors for a name whose slug is taken.');
        } catch (DomainErrors) {
        }

        self::assertCount(1, $this->rows($project));
    }

    /**
     * The row rides the flush that saves the project, so a save the database
     * refuses leaves no row claiming the project was renamed.
     */
    public function test_a_failed_save_leaves_no_row(): void
    {
        $project = $this->project('Before');
        $projectId = (string) $project->id;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $auditor = self::getContainer()->get(Auditor::class);
        self::assertInstanceOf(Auditor::class, $auditor);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $racing = new UpdateProjectHandler($projects, new RivalBeforeFlush($this->em, [
            'id' => (string) Uuid::v7(),
            'owner_id' => (string) $project->owner->id,
            'name' => 'My App',
            'slug' => 'my-app',
            'created_at' => '2026-09-12 00:00:00',
        ]), $auditor, $dispatcher);

        $pending = null;
        $em = $this->em;
        // After the listener under test, which runs at the default priority.
        $dispatcher->addListener(ProjectRenamed::class, static function () use ($em, &$pending): void {
            $pending = array_values(array_filter(
                $em->getUnitOfWork()->getScheduledEntityInsertions(),
                static fn (object $entity): bool => $entity instanceof OutboxEvent,
            ));
        }, -10);

        try {
            $racing(new UpdateProjectCommand($project, 'my-app', null, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));
            self::fail('Expected DomainErrors for a slug a concurrent rename took.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => 'project.error.slug_taken'], $e->errors);
        }

        // Without this the assertion below also passes when the listener never ran.
        self::assertIsArray($pending);
        self::assertCount(1, $pending);

        // The failed flush closes the entity manager, so the read goes through the connection.
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM outbox_events WHERE project_id = :id',
            ['id' => $projectId],
        ));
    }

    /** @param non-empty-string $name */
    private function rename(Project $project, string $name, ?string $domain = null): void
    {
        ($this->updateProject)(new UpdateProjectCommand($project, $name, $domain, SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));
    }

    private function project(string $name): Project
    {
        $owner = new User(fullName: 'Riley', email: 'project-outbox-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /** @return array<string, mixed> */
    private function decode(OutboxEvent $row): array
    {
        $decoded = json_decode($row->payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function onlyRow(Project $project): OutboxEvent
    {
        $rows = $this->rows($project);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return list<OutboxEvent> */
    private function rows(Project $project): array
    {
        return array_values($this->outbox->findBy(['project' => $project, 'type' => 'project.renamed']));
    }
}
