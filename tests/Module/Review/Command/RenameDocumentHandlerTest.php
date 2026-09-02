<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Audit\AuditChannel;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\RenameDocumentCommand;
use App\Module\Review\Command\RenameDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RenameDocumentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RenameDocumentHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(RenameDocumentHandler::class);
        self::assertInstanceOf(RenameDocumentHandler::class, $handler);
        $this->handler = $handler;
    }

    /** @param non-empty-string $email */
    private function document(string $email, string $title): Document
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $user, project: $project, title: $title);
        $document->addVersion('# Body', '<h1>Body</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_rename_persists_the_new_title_without_adding_a_version(): void
    {
        $document = $this->document('rename-ok@example.com', 'Post 5 — draft');
        $documentId = $document->id;
        self::assertInstanceOf(Uuid::class, $documentId);

        ($this->handler)(new RenameDocumentCommand($document, '  Post 5 — Rate limiting  '));

        $this->em->clear();
        $fresh = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame('Post 5 — Rate limiting', $fresh->title);
        self::assertCount(1, $fresh->versions);
    }

    public function test_a_blank_title_is_rejected(): void
    {
        $document = $this->document('rename-blank@example.com', 'Keep me');

        try {
            ($this->handler)(new RenameDocumentCommand($document, '   '));
            self::fail('a blank title must be rejected');
        } catch (DomainErrors $e) {
            self::assertSame(['title' => 'review.rename.error.blank'], $e->errors);
        }

        self::assertSame('Keep me', $document->title);
    }

    public function test_an_over_long_title_is_rejected_before_the_database_sees_it(): void
    {
        $document = $this->document('rename-long@example.com', 'Keep me');

        try {
            ($this->handler)(new RenameDocumentCommand($document, str_repeat('a', Document::MAX_TITLE_LENGTH + 1)));
            self::fail('an over-long title must be rejected');
        } catch (DomainErrors $e) {
            self::assertSame(['title' => 'review.rename.error.too_long'], $e->errors);
        }

        self::assertSame('Keep me', $document->title);
    }

    public function test_a_rename_is_recorded_on_the_domain_channel(): void
    {
        $document = $this->document('rename-audit@example.com', 'Before');

        ($this->handler)(new RenameDocumentCommand($document, 'After'));

        $record = $this->audit->record('review.document_renamed');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $document->id,
            'projectId' => (string) $document->project->id,
        ], $record->context);

        self::assertSame(['review.document_renamed'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /** A title is text a person wrote, and the context carries ids only. */
    public function test_the_record_carries_neither_title(): void
    {
        $document = $this->document('rename-titles@example.com', 'Salary review — Dana');

        ($this->handler)(new RenameDocumentCommand($document, 'Salary review — Dana Q3'));

        $context = $this->audit->record('review.document_renamed')->context;
        self::assertArrayNotHasKey('title', $context);
        self::assertArrayNotHasKey('previousTitle', $context);
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'Dana'),
        ));
    }

    public function test_a_rejected_rename_records_nothing(): void
    {
        $document = $this->document('rename-refused@example.com', 'Keep me');

        try {
            ($this->handler)(new RenameDocumentCommand($document, '   '));
            self::fail('a blank title must be rejected');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
    }

    /**
     * The whole log line, not only its message. Asserting the record alone
     * leaves what the sink emits unpinned, and the log stream is where the
     * titles used to be.
     */
    public function test_the_log_line_carries_the_record_and_nothing_a_person_wrote(): void
    {
        $document = $this->document('rename-log-line@example.com', 'Salary review — Dana');

        ($this->handler)(new RenameDocumentCommand($document, 'Salary review — Dana Q3'));

        self::assertCount(1, $this->audit->domainChannel->records);
        self::assertSame([
            'documentId' => (string) $document->id,
            'projectId' => (string) $document->project->id,
            'outcome' => 'success',
            'channel' => AuditChannel::System->value,
            'subjectType' => 'document',
            'subjectId' => (string) $document->id,
        ], $this->audit->domainChannel->records[0]['context']);
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(RenameDocumentHandler::class);
    }
}
