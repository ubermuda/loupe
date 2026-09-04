<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetDocumentSeriesCommand;
use App\Module\Review\Command\SetDocumentSeriesHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Repository\SeriesRepository;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class SetDocumentSeriesHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SetDocumentSeriesHandler $handler;
    private SeriesRepository $series;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(SetDocumentSeriesHandler::class);
        self::assertInstanceOf(SetDocumentSeriesHandler::class, $handler);
        $this->handler = $handler;

        $series = self::getContainer()->get(SeriesRepository::class);
        self::assertInstanceOf(SeriesRepository::class, $series);
        $this->series = $series;
    }

    /** @return array{Project, Document} */
    private function seed(string $slug): array
    {
        $user = new User(fullName: 'U', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.$slug);
        $this->em->persist($project);
        $document = $this->document($project, 'doc');
        $this->em->flush();

        return [$project, $document];
    }

    private function document(Project $project, string $title): Document
    {
        $document = new Document($project->owner, $project, $title);
        $document->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($document);

        return $document;
    }

    public function test_an_unknown_series_is_created_with_the_spelling_it_was_given(): void
    {
        [$project, $document] = $this->seed('series-create');

        $applied = ($this->handler)(new SetDocumentSeriesCommand($document, ' Blog  Series ', 5));

        self::assertInstanceOf(Series::class, $applied);
        self::assertSame('Blog Series', $applied->name);
        self::assertSame('blog series', $applied->normalizedName);
        self::assertSame(5, $document->seriesOrdinal);
        self::assertCount(1, $this->series->findBy(['project' => $project]));
    }

    public function test_an_existing_project_series_is_reused_rather_than_duplicated(): void
    {
        [$project, $document] = $this->seed('series-reuse');
        ($this->handler)(new SetDocumentSeriesCommand($document, 'blog series', 1));

        $second = $this->document($project, 'other doc');
        $this->em->flush();

        ($this->handler)(new SetDocumentSeriesCommand($second, 'BLOG SERIES', 2));

        self::assertCount(1, $this->series->findBy(['project' => $project]));
        // The first spelling, not the one this call used.
        self::assertSame('blog series', $second->series?->name);
    }

    public function test_the_same_name_in_two_projects_is_two_rows(): void
    {
        [$firstProject, $firstDocument] = $this->seed('series-scope-a');
        [$secondProject, $secondDocument] = $this->seed('series-scope-b');

        ($this->handler)(new SetDocumentSeriesCommand($firstDocument, 'blog series', 1));
        ($this->handler)(new SetDocumentSeriesCommand($secondDocument, 'blog series', 1));

        self::assertNotSame(
            (string) $this->series->findOneByProjectAndName($firstProject, 'blog series')?->id,
            (string) $this->series->findOneByProjectAndName($secondProject, 'blog series')?->id,
        );
    }

    public function test_two_documents_may_not_share_an_ordinal_in_one_series(): void
    {
        [$project, $first] = $this->seed('series-clash');
        ($this->handler)(new SetDocumentSeriesCommand($first, 'blog series', 3));
        $second = $this->document($project, 'other doc');
        $this->em->flush();

        try {
            ($this->handler)(new SetDocumentSeriesCommand($second, 'blog series', 3));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['seriesOrdinal' => 'review.series.error.ordinal_taken'], $e->errors);
        }

        self::assertNull($second->series);
        self::assertNull($second->seriesOrdinal);
    }

    public function test_resending_the_ordinal_a_document_already_holds_is_not_a_clash(): void
    {
        [, $document] = $this->seed('series-idempotent');
        ($this->handler)(new SetDocumentSeriesCommand($document, 'blog series', 3));

        ($this->handler)(new SetDocumentSeriesCommand($document, 'blog series', 3));

        self::assertSame(3, $document->seriesOrdinal);
    }

    public function test_the_same_ordinal_in_two_series_is_two_places(): void
    {
        [$project, $first] = $this->seed('series-two-sets');
        ($this->handler)(new SetDocumentSeriesCommand($first, 'blog series', 1));
        $second = $this->document($project, 'other doc');
        $this->em->flush();

        ($this->handler)(new SetDocumentSeriesCommand($second, 'companion threads', 1));

        self::assertSame('companion threads', $second->series?->name);
        self::assertSame(1, $second->seriesOrdinal);
    }

    public function test_passing_neither_takes_the_document_out_without_deleting_the_series(): void
    {
        [$project, $document] = $this->seed('series-clear');
        ($this->handler)(new SetDocumentSeriesCommand($document, 'blog series', 1));

        $applied = ($this->handler)(new SetDocumentSeriesCommand($document, null, null));

        self::assertNull($applied);
        self::assertNull($document->series);
        self::assertNull($document->seriesOrdinal);
        // The vocabulary outlives the documents that used it.
        self::assertCount(1, $this->series->findBy(['project' => $project]));
    }

    public function test_the_placement_survives_a_reload(): void
    {
        [, $document] = $this->seed('series-reload');
        ($this->handler)(new SetDocumentSeriesCommand($document, 'blog series', 11));
        $documentId = $document->id;
        $this->em->clear();

        $reloaded = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertSame('blog series', $reloaded->series?->name);
        self::assertSame(11, $reloaded->seriesOrdinal);
    }

    /**
     * The handler's own pre-check means every other test here would still pass
     * with the unique index dropped from the migration. This one asserts the
     * index itself, so regenerating the migration cannot lose the invariant.
     */
    public function test_the_database_rejects_a_duplicate_ordinal_in_one_series(): void
    {
        [$project, $document] = $this->seed('series-unique');
        ($this->handler)(new SetDocumentSeriesCommand($document, 'blog series', 7));
        $seriesId = (string) $this->series->findOneByProjectAndName($project, 'blog series')?->id;
        $other = $this->document($project, 'other doc');
        $this->em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'UPDATE documents SET series_id = :series, series_ordinal = 7 WHERE id = :id',
            ['series' => $seriesId, 'id' => (string) $other->id],
        );
    }

    public function test_a_rejected_ordinal_is_not_recorded(): void
    {
        [$project, $first] = $this->seed('series-audit-refused');
        ($this->handler)(new SetDocumentSeriesCommand($first, 'blog series', 1));
        $this->audit->forget();
        $second = $this->document($project, 'other doc');
        $this->em->flush();

        try {
            ($this->handler)(new SetDocumentSeriesCommand($second, 'blog series', 1));
            self::fail('expected DomainErrors');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_a_placement_is_recorded_on_the_domain_channel_without_the_name(): void
    {
        [$project, $document] = $this->seed('series-audit');

        ($this->handler)(new SetDocumentSeriesCommand($document, 'dana okafor reading list', 2));

        $record = $this->audit->record('review.document_series_updated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $document->id,
            'projectId' => (string) $project->id,
            'inSeries' => true,
            'seriesOrdinal' => 2,
        ], $record->context);

        self::assertSame([], $this->audit->securityLogLines());
    }
}
