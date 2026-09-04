<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Repository\SeriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SeriesRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SeriesRepository $series;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $series = self::getContainer()->get(SeriesRepository::class);
        self::assertInstanceOf(SeriesRepository::class, $series);
        $this->series = $series;
    }

    private function project(string $slug): Project
    {
        $user = new User(fullName: 'U', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.$slug);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function documentIn(Project $project, string $title, Series $series, int $ordinal): Document
    {
        $document = new Document($project->owner, $project, $title);
        $document->addVersion('# '.$title, '<h1>'.$title.'</h1>');
        $document->series = $series;
        $document->seriesOrdinal = $ordinal;
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /** Inserts a series with no ORM involvement, the way a sibling request's commit leaves one. */
    private function insertBehindTheOrmsBack(Project $project, string $name): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO series (id, project_id, name, created_at) VALUES (:id, :project, :name, NOW())',
            ['id' => (string) Uuid::v7(), 'project' => (string) $project->id, 'name' => $name],
        );
    }

    public function test_the_name_is_normalised_before_it_reaches_the_insert(): void
    {
        $project = $this->project('seriesrepo-normalise');

        $series = $this->series->findOrCreate($project, '  Blog   Series ');

        self::assertSame('blog series', $series->name);
        self::assertCount(1, $this->series->findBy(['project' => $project]));
    }

    public function test_calling_it_twice_returns_the_same_row(): void
    {
        $project = $this->project('seriesrepo-twice');

        $first = $this->series->findOrCreate($project, 'blog series');
        $second = $this->series->findOrCreate($project, 'BLOG SERIES');

        self::assertSame((string) $first->id, (string) $second->id);
        self::assertCount(1, $this->series->findBy(['project' => $project]));
    }

    /**
     * The half of the race one connection can express: the row is already
     * committed and this EntityManager has never seen it. It covers that the
     * conflict target matches the index, that the insert is absorbed instead of
     * raising, and that the re-read returns the other request's row.
     */
    public function test_a_row_another_request_committed_is_adopted_not_duplicated(): void
    {
        $project = $this->project('seriesrepo-race');
        $this->insertBehindTheOrmsBack($project, 'blog series');

        $series = $this->series->findOrCreate($project, 'Blog Series');

        self::assertSame('blog series', $series->name);
        self::assertCount(1, $this->series->findBy(['project' => $project]));
    }

    public function test_the_conflict_is_scoped_to_the_project(): void
    {
        $mine = $this->project('seriesrepo-scope-a');
        $theirs = $this->project('seriesrepo-scope-b');
        $this->insertBehindTheOrmsBack($theirs, 'blog series');

        $series = $this->series->findOrCreate($mine, 'blog series');

        self::assertSame((string) $mine->id, (string) $series->project->id);
        self::assertCount(1, $this->series->findBy(['project' => $mine]));
        self::assertCount(1, $this->series->findBy(['project' => $theirs]));
    }

    public function test_the_counts_carry_the_highest_ordinal_and_keep_an_empty_series(): void
    {
        $project = $this->project('seriesrepo-counts');
        $used = $this->series->findOrCreate($project, 'blog series');
        $this->series->findOrCreate($project, 'abandoned');
        $this->documentIn($project, 'Post one', $used, 1);
        $this->documentIn($project, 'Post four', $used, 4);

        $rows = $this->series->findByProjectWithDocumentCounts($project);

        self::assertSame(
            [
                ['abandoned', 0, null],
                ['blog series', 2, 4],
            ],
            array_map(
                static fn (array $row): array => [$row['series']->name, $row['documentCount'], $row['highestOrdinal']],
                $rows,
            ),
        );
    }
}
