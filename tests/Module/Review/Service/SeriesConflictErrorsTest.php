<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Service\SeriesConflictErrors;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Driven by violations Postgres actually raises rather than hand-built
 * exceptions, so a renamed index breaks this test instead of turning the
 * mapping into a silent fall-through.
 */
final class SeriesConflictErrorsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SeriesConflictErrors $conflicts;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $conflicts = self::getContainer()->get(SeriesConflictErrors::class);
        self::assertInstanceOf(SeriesConflictErrors::class, $conflicts);
        $this->conflicts = $conflicts;
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

    public function test_a_duplicate_ordinal_reads_back_as_the_ordinal_domain_error(): void
    {
        $project = $this->project('conflict-ordinal');
        $series = new Series($project, 'blog series');
        $this->em->persist($series);
        foreach (['Post one', 'Also post one'] as $title) {
            $document = new Document($project->owner, $project, $title);
            $document->addVersion('# hi', '<h1>hi</h1>');
            $document->series = $series;
            $document->seriesOrdinal = 1;
            $this->em->persist($document);
        }

        try {
            $this->em->flush();
            self::fail('expected the unique index to reject the second document');
        } catch (UniqueConstraintViolationException $e) {
            $errors = $this->conflicts->forViolation($e);
        }

        self::assertNotNull($errors);
        self::assertSame(['seriesOrdinal' => 'review.series.error.ordinal_taken'], $errors->errors);
    }

    public function test_a_duplicate_series_name_reads_back_as_the_name_domain_error(): void
    {
        $project = $this->project('conflict-name');
        $this->em->getConnection()->executeStatement(
            'INSERT INTO series (id, project_id, name, created_at) VALUES (:id, :project, :name, NOW())',
            ['id' => (string) Uuid::v7(), 'project' => (string) $project->id, 'name' => 'blog series'],
        );

        try {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO series (id, project_id, name, created_at) VALUES (:id, :project, :name, NOW())',
                ['id' => (string) Uuid::v7(), 'project' => (string) $project->id, 'name' => 'blog series'],
            );
            self::fail('expected the unique index to reject the second series');
        } catch (UniqueConstraintViolationException $e) {
            $errors = $this->conflicts->forViolation($e);
        }

        self::assertNotNull($errors);
        self::assertSame(['series' => 'review.series.error.name_taken'], $errors->errors);
    }

    public function test_a_violation_of_another_index_is_left_to_the_caller(): void
    {
        $project = $this->project('conflict-other');
        $this->em->getConnection()->executeStatement(
            'INSERT INTO tags (id, project_id, name, created_at) VALUES (:id, :project, :name, NOW())',
            ['id' => (string) Uuid::v7(), 'project' => (string) $project->id, 'name' => 'design'],
        );

        try {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO tags (id, project_id, name, created_at) VALUES (:id, :project, :name, NOW())',
                ['id' => (string) Uuid::v7(), 'project' => (string) $project->id, 'name' => 'design'],
            );
            self::fail('expected the unique index to reject the second tag');
        } catch (UniqueConstraintViolationException $e) {
            $errors = $this->conflicts->forViolation($e);
        }

        self::assertNull($errors);
    }
}
