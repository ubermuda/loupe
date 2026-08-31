<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ArchiveDocumentCommand;
use App\Module\Review\Command\ArchiveDocumentHandler;
use App\Module\Review\Command\UnarchiveDocumentCommand;
use App\Module\Review\Command\UnarchiveDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ArchiveDocumentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ArchiveDocumentHandler $archive;
    private UnarchiveDocumentHandler $unarchive;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $archive = self::getContainer()->get(ArchiveDocumentHandler::class);
        self::assertInstanceOf(ArchiveDocumentHandler::class, $archive);
        $this->archive = $archive;

        $unarchive = self::getContainer()->get(UnarchiveDocumentHandler::class);
        self::assertInstanceOf(UnarchiveDocumentHandler::class, $unarchive);
        $this->unarchive = $unarchive;
    }

    /** @param non-empty-string $email */
    private function document(string $email): Document
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $user, project: $project, title: 'A doc');
        $document->addVersion('# Body', '<h1>Body</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_archiving_stamps_a_timestamp_and_unarchiving_clears_it(): void
    {
        $document = $this->document('archive-roundtrip@example.com');
        $documentId = $document->id;
        self::assertInstanceOf(Uuid::class, $documentId);

        ($this->archive)(new ArchiveDocumentCommand($document));

        $this->em->clear();
        $archived = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $archived);
        self::assertNotNull($archived->archivedAt);

        ($this->unarchive)(new UnarchiveDocumentCommand($archived));

        $this->em->clear();
        $restored = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $restored);
        self::assertNull($restored->archivedAt);
    }

    public function test_archiving_leaves_the_review_status_alone(): void
    {
        $document = $this->document('archive-status@example.com');
        $document->status = DocumentStatus::Approved;
        $this->em->flush();

        ($this->archive)(new ArchiveDocumentCommand($document));

        self::assertSame(DocumentStatus::Approved, $document->status);
        self::assertNotNull($document->archivedAt);
    }

    public function test_archiving_twice_keeps_the_first_timestamp(): void
    {
        $document = $this->document('archive-twice@example.com');

        ($this->archive)(new ArchiveDocumentCommand($document));
        $first = $document->archivedAt;

        ($this->archive)(new ArchiveDocumentCommand($document));

        self::assertSame($first, $document->archivedAt);
    }

    public function test_a_reason_is_stored_and_survives_a_round_trip_through_the_database(): void
    {
        $document = $this->document('archive-reason@example.com');
        $documentId = $document->id;
        self::assertInstanceOf(Uuid::class, $documentId);

        ($this->archive)(new ArchiveDocumentCommand($document, 'superseded by the v2 plan'));

        $this->em->clear();
        $archived = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $archived);
        self::assertSame('superseded by the v2 plan', $archived->archiveReason);
    }

    /** Archiving from the app passes no reason, and that is the ordinary case rather than missing data. */
    public function test_archiving_without_a_reason_leaves_it_null(): void
    {
        $document = $this->document('archive-no-reason@example.com');

        ($this->archive)(new ArchiveDocumentCommand($document));

        self::assertNotNull($document->archivedAt);
        self::assertNull($document->archiveReason);
    }

    /**
     * The handler serializes the guard and the write under a row lock so a
     * racing second archive cannot overwrite this reason. That race is not
     * expressible here — every test runs inside one connection's transaction,
     * so two overlapping database transactions cannot exist — and the lock is
     * verified by review. This sequential case is the regression guard.
     */
    public function test_archiving_twice_keeps_the_first_reason(): void
    {
        $document = $this->document('archive-2x-reason@example.com');

        ($this->archive)(new ArchiveDocumentCommand($document, 'superseded'));
        ($this->archive)(new ArchiveDocumentCommand($document, 'duplicate'));

        self::assertSame('superseded', $document->archiveReason);
    }

    public function test_a_reason_is_stored_trimmed(): void
    {
        $document = $this->document('archive-trim@example.com');

        ($this->archive)(new ArchiveDocumentCommand($document, "  superseded by the v2 plan\n"));

        self::assertSame('superseded by the v2 plan', $document->archiveReason);
    }

    /**
     * A caller asked for a reason and answering with spaces is not the same as
     * the app's button, which is never asked at all: the first is rejected, the
     * second stores null.
     */
    public function test_a_blank_reason_is_rejected_and_the_document_stays_live(): void
    {
        $document = $this->document('archive-blank@example.com');

        try {
            ($this->archive)(new ArchiveDocumentCommand($document, '   '));
            self::fail('a blank reason must be rejected');
        } catch (DomainErrors $e) {
            self::assertSame(['reason' => 'review.archive.error.reason_blank'], $e->errors);
        }

        self::assertNull($document->archivedAt);
        self::assertNull($document->archiveReason);
    }

    /** A document back in the list must not still explain why it once left it. */
    public function test_unarchiving_clears_the_reason(): void
    {
        $document = $this->document('unarchive-reason@example.com');
        $documentId = $document->id;
        self::assertInstanceOf(Uuid::class, $documentId);

        ($this->archive)(new ArchiveDocumentCommand($document, 'superseded by the v2 plan'));
        ($this->unarchive)(new UnarchiveDocumentCommand($document));

        $this->em->clear();
        $restored = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $restored);
        self::assertNull($restored->archivedAt);
        self::assertNull($restored->archiveReason);
    }

    public function test_archiving_is_recorded_on_the_domain_channel(): void
    {
        $document = $this->document('archive-audit@example.com');

        ($this->archive)(new ArchiveDocumentCommand($document, null));

        $record = $this->audit->record('review.document.archived');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $document->id,
            'projectId' => (string) $document->project->id,
        ], $record->context);

        self::assertSame(['review.document.archived'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /** The reason is a sentence a reviewer wrote, so it stays out of the trail. */
    public function test_the_archive_record_carries_no_reason(): void
    {
        $document = $this->document('archive-audit-reason@example.com');

        ($this->archive)(new ArchiveDocumentCommand($document, 'superseded by Dana Okafor'));

        $context = $this->audit->record('review.document.archived')->context;
        self::assertArrayNotHasKey('reason', $context);
        self::assertArrayNotHasKey('archiveReason', $context);
        self::assertSame('superseded by Dana Okafor', $document->archiveReason);
    }

    public function test_unarchiving_is_recorded_on_the_domain_channel(): void
    {
        $document = $this->document('unarchive-audit@example.com');
        ($this->archive)(new ArchiveDocumentCommand($document, null));

        ($this->unarchive)(new UnarchiveDocumentCommand($document));

        $record = $this->audit->record('review.document.unarchived');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame([
            'documentId' => (string) $document->id,
            'projectId' => (string) $document->project->id,
        ], $record->context);

        self::assertSame(
            ['review.document.archived', 'review.document.unarchived'],
            $this->audit->domainLogLines(),
        );
    }

    /**
     * Both handlers stay silent where they already were: archiving twice keeps
     * the first timestamp, and unarchiving a live document changes nothing.
     */
    public function test_a_state_change_that_does_not_happen_records_nothing(): void
    {
        $document = $this->document('archive-audit-noop@example.com');

        ($this->unarchive)(new UnarchiveDocumentCommand($document));
        self::assertSame([], $this->audit->operations());

        ($this->archive)(new ArchiveDocumentCommand($document, null));
        ($this->archive)(new ArchiveDocumentCommand($document, null));
        self::assertSame(['review.document.archived'], $this->audit->operations());
    }

    public function test_a_rejected_archive_records_nothing(): void
    {
        $document = $this->document('archive-audit-refused@example.com');

        try {
            ($this->archive)(new ArchiveDocumentCommand($document, '   '));
            self::fail('a blank reason must be rejected');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_neither_handler_keeps_a_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(ArchiveDocumentHandler::class);
        DirectLogging::assertRemovedFrom(UnarchiveDocumentHandler::class);
    }
}
