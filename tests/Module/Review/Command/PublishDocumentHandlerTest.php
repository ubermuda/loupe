<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditOutcome;

final class PublishDocumentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PublishDocumentHandler $publish;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $publish = self::getContainer()->get(PublishDocumentHandler::class);
        self::assertInstanceOf(PublishDocumentHandler::class, $publish);
        $this->publish = $publish;
    }

    /** @param non-empty-string $email */
    private function document(string $email, DocumentStatus $status): Document
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $user, project: $project, title: 'A doc');
        $document->addVersion('# Body', '<h1>Body</h1>');
        $document->status = $status;
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_publishing_a_draft_sends_it_to_review_and_announces_it_after_the_commit(): void
    {
        $document = $this->document('publish-draft@example.com', DocumentStatus::Draft);
        $documentId = $document->id;
        $changes = DispatchedEvents::of(self::getContainer(), DocumentStatusChanged::class);
        $depth = $this->em->getConnection()->getTransactionNestingLevel();

        self::assertTrue(($this->publish)(new PublishDocumentCommand($document)));

        $this->em->clear();
        $fresh = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame(DocumentStatus::InReview, $fresh->status);

        self::assertCount(1, $changes->events());
        self::assertEquals($document->project->id, $changes->events()[0]->projectId);
        self::assertEquals($documentId, $changes->events()[0]->documentId);
        self::assertSame([$depth], $changes->transactionDepths());

        $record = $this->audit->record('review.document_published');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame([
            'documentId' => (string) $documentId,
            'projectId' => (string) $document->project->id,
        ], $record->context);
    }

    public function test_publishing_a_document_in_review_changes_nothing_and_announces_nothing(): void
    {
        $document = $this->document('publish-in-review@example.com', DocumentStatus::InReview);
        $changes = DispatchedEvents::of(self::getContainer(), DocumentStatusChanged::class);

        self::assertFalse(($this->publish)(new PublishDocumentCommand($document)));

        self::assertSame(DocumentStatus::InReview, $document->status);
        self::assertSame([], $changes->events());
        self::assertSame([], $this->audit->records('review.document_published'));
    }

    public function test_publishing_an_approved_document_leaves_it_approved(): void
    {
        $document = $this->document('publish-approved@example.com', DocumentStatus::Approved);

        self::assertFalse(($this->publish)(new PublishDocumentCommand($document)));

        self::assertSame(DocumentStatus::Approved, $document->status);
    }

    public function test_publishing_an_archived_draft_is_refused(): void
    {
        $document = $this->document('publish-archived@example.com', DocumentStatus::Draft);
        $document->archivedAt = new \DateTimeImmutable();
        $this->em->flush();
        $documentId = $document->id;
        $changes = DispatchedEvents::of(self::getContainer(), DocumentStatusChanged::class);

        try {
            ($this->publish)(new PublishDocumentCommand($document));
            self::fail('an archived document must be refused');
        } catch (DomainErrors $e) {
            self::assertSame(['documentId' => 'review.publish.error.archived'], $e->errors);
        }

        self::assertTrue($this->em->isOpen());
        $this->em->clear();
        $fresh = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame(DocumentStatus::Draft, $fresh->status);
        self::assertSame([], $changes->events());
    }

    /** The guard reads the row, so a loaded copy that is behind the database still decides correctly. */
    public function test_a_draft_another_transaction_already_published_is_not_published_twice(): void
    {
        $document = $this->document('publish-stale@example.com', DocumentStatus::Draft);
        $this->em->getConnection()->executeStatement(
            'UPDATE documents SET status = :status WHERE id = :id',
            ['status' => DocumentStatus::InReview->value, 'id' => $document->id?->toRfc4122()],
        );

        self::assertFalse(($this->publish)(new PublishDocumentCommand($document)));
        self::assertSame(DocumentStatus::InReview, $document->status);
        self::assertSame([], $this->audit->records('review.document_published'));
    }
}
