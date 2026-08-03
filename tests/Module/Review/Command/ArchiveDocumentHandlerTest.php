<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ArchiveDocumentCommand;
use App\Module\Review\Command\ArchiveDocumentHandler;
use App\Module\Review\Command\UnarchiveDocumentCommand;
use App\Module\Review\Command\UnarchiveDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ArchiveDocumentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ArchiveDocumentHandler $archive;
    private UnarchiveDocumentHandler $unarchive;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

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
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
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
}
