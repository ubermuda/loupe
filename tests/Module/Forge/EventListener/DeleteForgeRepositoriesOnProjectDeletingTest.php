<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\EventListener\DeleteForgeRepositoriesOnProjectDeleting;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The listener runs alone here. Through ProjectDeleter the foreign key's
 * cascade would remove the rows too, and hide a listener that did not.
 */
final class DeleteForgeRepositoriesOnProjectDeletingTest extends KernelTestCase
{
    public function test_it_deletes_the_repositories_of_its_project_only(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $listener = self::getContainer()->get(DeleteForgeRepositoriesOnProjectDeleting::class);
        self::assertInstanceOf(DeleteForgeRepositoriesOnProjectDeleting::class, $listener);

        $owner = new User(fullName: 'Riley', email: 'forge-delete-'.uniqid().'@example.com', password: 'hashed');
        $doomed = new Project($owner, 'doomed-'.uniqid());
        $spared = new Project($owner, 'spared-'.uniqid());
        $em->persist($owner);
        $em->persist($doomed);
        $em->persist($spared);
        $em->persist(new ForgeRepository($doomed, 'github', 'delete-1', 'acme/one'));
        $em->persist(new ForgeRepository($doomed, 'github', 'delete-2', 'acme/two'));
        $em->persist(new ForgeRepository($spared, 'github', 'delete-3', 'acme/three'));
        $em->flush();

        $conn = $em->getConnection();
        $count = static fn (Project $project): int => (int) $conn->fetchOne('SELECT COUNT(*) FROM forge_repositories WHERE project_id = :id', ['id' => (string) $project->id]);
        self::assertSame(2, $count($doomed));

        $listener(new ProjectDeleting($doomed));

        self::assertSame(0, $count($doomed));
        self::assertSame(1, $count($spared));
    }
}
