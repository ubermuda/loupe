<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\DeleteProjectCommand;
use App\Module\Project\Command\DeleteProjectHandler;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteProjectHandlerTest extends KernelTestCase
{
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

    /** @return array{0: EntityManagerInterface, 1: DeleteProjectHandler, 2: Project} */
    private function boot(string $projectName): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $handler = self::getContainer()->get(DeleteProjectHandler::class);
        self::assertInstanceOf(DeleteProjectHandler::class, $handler);

        $owner = new User(username: 'delete-handler-owner', fullName: 'Owner', email: 'delete-handler-owner@example.test', password: 'irrelevant-hash');
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
