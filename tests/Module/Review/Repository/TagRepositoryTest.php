<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class TagRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TagRepository $tags;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tags = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $tags);
        $this->tags = $tags;
    }

    private function project(string $slug): Project
    {
        $user = new User(username: $slug, fullName: 'U', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.$slug);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /** Inserts a tag with no ORM involvement, the way a sibling request's commit leaves one. */
    private function insertBehindTheOrmsBack(Project $project, string $name): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO tags (id, project_id, name, created_at) VALUES (:id, :project, :name, NOW())',
            ['id' => (string) Uuid::v7(), 'project' => (string) $project->id, 'name' => $name],
        );
    }

    public function test_the_name_is_normalised_before_it_reaches_the_insert(): void
    {
        $project = $this->project('tagrepo-normalise');

        $tag = $this->tags->findOrCreate($project, '  Design   Spec ');

        self::assertSame('design spec', $tag->name);
        self::assertCount(1, $this->tags->findBy(['project' => $project]));
    }

    public function test_calling_it_twice_returns_the_same_row(): void
    {
        $project = $this->project('tagrepo-twice');

        $first = $this->tags->findOrCreate($project, 'design');
        $second = $this->tags->findOrCreate($project, 'DESIGN');

        self::assertSame((string) $first->id, (string) $second->id);
        self::assertCount(1, $this->tags->findBy(['project' => $project]));
    }

    /**
     * The losing half of the race, as far as one connection can express it: the
     * row is already committed and this EntityManager has never seen it, which is
     * the state a sibling request leaves behind.
     *
     * What this does NOT cover is the interleaving that produces it — two
     * overlapping transactions are not expressible here, because
     * dama/doctrine-test-bundle runs each test inside a single connection's
     * transaction. What it does cover is the half that used to throw: that the
     * conflict target matches the index, that the insert is absorbed instead of
     * raising, and that the re-read returns the other request's row rather than a
     * second one.
     */
    public function test_a_row_another_request_committed_is_adopted_not_duplicated(): void
    {
        $project = $this->project('tagrepo-race');
        $this->insertBehindTheOrmsBack($project, 'design');

        $tag = $this->tags->findOrCreate($project, 'Design');

        self::assertSame('design', $tag->name);
        self::assertCount(1, $this->tags->findBy(['project' => $project]));
    }

    public function test_the_conflict_is_scoped_to_the_project(): void
    {
        $mine = $this->project('tagrepo-scope-a');
        $theirs = $this->project('tagrepo-scope-b');
        $this->insertBehindTheOrmsBack($theirs, 'design');

        // Another project already holding the name must not suppress this insert.
        $tag = $this->tags->findOrCreate($mine, 'design');

        self::assertSame((string) $mine->id, (string) $tag->project->id);
        self::assertCount(1, $this->tags->findBy(['project' => $mine]));
        self::assertCount(1, $this->tags->findBy(['project' => $theirs]));
    }

    public function test_ids_match_the_generator_the_mapping_declares(): void
    {
        $project = $this->project('tagrepo-ids');

        $viaRepository = $this->tags->findOrCreate($project, 'design');

        // persist() runs the mapping's CustomIdGenerator, so this is the id the
        // ORM would have minted had the insert gone through it.
        $viaOrm = new Tag($project, 'release');
        $this->em->persist($viaOrm);

        self::assertNotNull($viaOrm->id);
        self::assertNotNull($viaRepository->id);
        self::assertSame($viaOrm->id::class, $viaRepository->id::class);
    }
}
