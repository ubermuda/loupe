<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Command\DeleteProjectCommand;
use App\Module\Project\Command\DeleteProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteProjectHandlerTest extends KernelTestCase
{
    private RecordingAuditor $audit;

    public function test_mismatched_name_throws_domain_errors_and_deletes_nothing(): void
    {
        [$em, $handler, $project] = $this->boot('real-name');

        try {
            ($handler)(new DeleteProjectCommand(project: $project, confirmedName: 'other'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['confirmName' => 'project.delete.error.name_mismatch'], $e->errors);
        }

        $em->clear();
        self::assertNotNull($em->find(Project::class, $project->id));
    }

    public function test_whitespace_variant_is_a_mismatch(): void
    {
        [$em, $handler, $project] = $this->boot('real-name');

        try {
            ($handler)(new DeleteProjectCommand(project: $project, confirmedName: ' real-name '));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['confirmName' => 'project.delete.error.name_mismatch'], $e->errors);
        }

        $em->clear();
        self::assertNotNull($em->find(Project::class, $project->id));
    }

    public function test_exact_name_deletes(): void
    {
        [$em, $handler, $project] = $this->boot('real-name');
        $projectId = $project->id;

        ($handler)(new DeleteProjectCommand(project: $project, confirmedName: 'real-name'));

        $em->clear();
        self::assertNull($em->find(Project::class, $projectId));
    }

    /**
     * ProjectDeleter records the deletion itself, but it also runs under
     * account deletion, so only the handler can say the owner asked for it.
     */
    public function test_a_deliberate_deletion_is_recorded_as_a_request(): void
    {
        [, $handler, $project] = $this->boot('real-name');
        $projectId = (string) $project->id;

        ($handler)(new DeleteProjectCommand(project: $project, confirmedName: 'real-name'));

        $record = $this->audit->record('project.deletion_requested');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('project', $record->subject->type);
        self::assertSame($projectId, $record->subject->id);
        self::assertSame(['projectId' => $projectId], $record->context);

        self::assertSame(['project.deletion_requested'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /**
     * The record drains at kernel.terminate, so one written before the delete
     * outlives the rollback that a failed delete causes.
     */
    public function test_a_failed_deletion_records_no_request(): void
    {
        [$em, $handler, $project] = $this->boot('real-name');
        $projectId = $project->id;

        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(ProjectDeleting::class, static function (): void {
            throw new \RuntimeException('boom');
        }, -100);

        try {
            ($handler)(new DeleteProjectCommand(project: $project, confirmedName: 'real-name'));
            self::fail('expected the listener exception to propagate');
        } catch (\RuntimeException) {
        }

        $em->clear();
        self::assertNotNull($em->find(Project::class, $projectId));
        self::assertSame([], $this->audit->operations());
    }

    public function test_a_mismatched_name_records_no_request(): void
    {
        [, $handler, $project] = $this->boot('real-name');

        try {
            ($handler)(new DeleteProjectCommand(project: $project, confirmedName: 'other'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
    }

    /** @return array{0: EntityManagerInterface, 1: DeleteProjectHandler, 2: Project} */
    private function boot(string $projectName): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        // The container's ProjectDeleter keeps the real Auditor, so this sink
        // sees the handler's own record and not the deleter's.
        $this->audit = new RecordingAuditor($actors);
        $handler = new DeleteProjectHandler($deleter, $this->audit->auditor);

        $owner = new User(fullName: 'Owner', email: 'delete-handler-owner@example.test', password: 'irrelevant-hash');
        $em->persist($owner);
        $project = new Project(owner: $owner, name: $projectName);
        $em->persist($project);
        $em->flush();
        $em->clear();

        $project = $em->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $project);

        return [$em, $handler, $project];
    }
}
