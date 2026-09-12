<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\AmbiguousProjectHandleException;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectHandleLookupTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProjectRepository $projects;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $this->projects = $projects;
    }

    public function test_a_slug_resolves_its_project(): void
    {
        $owner = $this->owner('handle-slug@example.com');
        $project = new Project($owner, 'Handle Site');
        $this->em->persist($project);
        $this->em->flush();

        self::assertSame($project, $this->projects->findOneByHandleForOwner('handle-site', $owner));
        self::assertSame($project, $this->projects->findOneByHandleForOwner('Handle Site', $owner));
    }

    public function test_a_handle_that_is_one_slug_and_another_name_is_refused(): void
    {
        $owner = $this->owner('handle-backfill@example.com');
        $older = new Project($owner, 'My App');
        $newer = new Project($owner, 'my-app');
        // The state the backfill leaves for this pair: the older keeps the plain slug.
        new \ReflectionProperty(Project::class, 'slug')->setRawValue($newer, 'my-app-2');
        $this->em->persist($older);
        $this->em->persist($newer);
        $this->em->flush();

        try {
            $this->projects->findOneByHandleForOwner('my-app', $owner);
            self::fail('Expected an ambiguous handle to be refused.');
        } catch (AmbiguousProjectHandleException $e) {
            self::assertSame($older, $e->bySlug);
            self::assertSame($newer, $e->byName);
            self::assertStringContainsString((string) $older->id, $e->getMessage());
            self::assertStringContainsString((string) $newer->id, $e->getMessage());
        }

        self::assertSame($newer, $this->projects->findOneByHandleForOwner('my-app-2', $owner));
        self::assertSame($older, $this->projects->findOneByHandleForOwner('My App', $owner));
    }

    public function test_a_slug_that_is_also_its_own_projects_name_resolves(): void
    {
        $owner = $this->owner('handle-same@example.com');
        $project = new Project($owner, 'plain-name');
        $this->em->persist($project);
        $this->em->flush();

        self::assertSame($project, $this->projects->findOneByHandleForOwner('plain-name', $owner));
    }

    public function test_another_owners_slug_resolves_nothing(): void
    {
        $this->em->persist(new Project($this->owner('handle-theirs@example.com'), 'Their Site'));
        $mine = $this->owner('handle-mine@example.com');
        $this->em->flush();

        self::assertNull($this->projects->findOneByHandleForOwner('their-site', $mine));
    }

    /** @param non-empty-string $email */
    private function owner(string $email): User
    {
        $owner = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($owner);

        return $owner;
    }
}
