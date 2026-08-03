<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\RenameDocumentCommand;
use App\Module\Review\Command\RenameDocumentHandler;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RenameDocumentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RenameDocumentHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(RenameDocumentHandler::class);
        self::assertInstanceOf(RenameDocumentHandler::class, $handler);
        $this->handler = $handler;
    }

    /** @param non-empty-string $email */
    private function document(string $email, string $title): Document
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
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
}
