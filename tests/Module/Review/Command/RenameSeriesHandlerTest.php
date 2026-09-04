<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\RenameSeriesCommand;
use App\Module\Review\Command\RenameSeriesHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Repository\SeriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RenameSeriesHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RenameSeriesHandler $handler;
    private SeriesRepository $series;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(RenameSeriesHandler::class);
        self::assertInstanceOf(RenameSeriesHandler::class, $handler);
        $this->handler = $handler;

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

    public function test_the_new_name_is_normalised_and_the_documents_keep_their_places(): void
    {
        $project = $this->project('rename-series');
        $series = $this->series->findOrCreate($project, 'blog series');
        $document = new Document($project->owner, $project, 'Post one');
        $document->addVersion('# hi', '<h1>hi</h1>');
        $document->series = $series;
        $document->seriesOrdinal = 1;
        $this->em->persist($document);
        $this->em->flush();
        $documentId = $document->id;

        ($this->handler)(new RenameSeriesCommand($series, '  Rust  Atomics '));
        $this->em->clear();

        $reloaded = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertSame('rust atomics', $reloaded->series?->name);
        self::assertSame(1, $reloaded->seriesOrdinal);
    }

    public function test_the_renamed_series_is_findable_under_its_new_name(): void
    {
        $project = $this->project('rename-lookup');
        $series = $this->series->findOrCreate($project, 'blog series');

        ($this->handler)(new RenameSeriesCommand($series, 'Rust Atomics'));

        self::assertNull($this->series->findOneByProjectAndName($project, 'blog series'));
        self::assertInstanceOf(Series::class, $this->series->findOneByProjectAndName($project, 'rust atomics'));
    }

    public function test_renaming_a_series_to_the_name_it_already_has_is_accepted(): void
    {
        $project = $this->project('rename-noop');
        $series = $this->series->findOrCreate($project, 'blog series');

        $renamed = ($this->handler)(new RenameSeriesCommand($series, 'BLOG SERIES'));

        self::assertSame('blog series', $renamed->name);
    }

    public function test_a_name_another_series_holds_is_refused_rather_than_merged(): void
    {
        $project = $this->project('rename-clash');
        $series = $this->series->findOrCreate($project, 'blog series');
        $this->series->findOrCreate($project, 'companion threads');

        try {
            ($this->handler)(new RenameSeriesCommand($series, 'companion threads'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['series' => 'review.series.error.name_taken'], $e->errors);
        }

        self::assertSame('blog series', $series->name);
    }

    public function test_a_name_a_sibling_project_holds_is_free(): void
    {
        $mine = $this->project('rename-scope-a');
        $theirs = $this->project('rename-scope-b');
        $series = $this->series->findOrCreate($mine, 'blog series');
        $this->series->findOrCreate($theirs, 'rust atomics');

        $renamed = ($this->handler)(new RenameSeriesCommand($series, 'rust atomics'));

        self::assertSame('rust atomics', $renamed->name);
    }

    public function test_a_blank_name_is_refused(): void
    {
        $project = $this->project('rename-blank');
        $series = $this->series->findOrCreate($project, 'blog series');

        $this->expectExceptionObject(new DomainErrors(['series' => 'review.series.error.name_required']));

        ($this->handler)(new RenameSeriesCommand($series, '   '));
    }

    public function test_an_over_long_name_is_refused(): void
    {
        $project = $this->project('rename-too-long');
        $series = $this->series->findOrCreate($project, 'blog series');

        $this->expectExceptionObject(new DomainErrors(['series' => 'review.series.error.too_long']));

        ($this->handler)(new RenameSeriesCommand($series, str_repeat('a', Series::MAX_NAME_LENGTH + 1)));
    }
}
