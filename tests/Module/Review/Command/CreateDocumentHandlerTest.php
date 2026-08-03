<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateDocumentHandlerTest extends KernelTestCase
{
    public function test_creates_document_with_first_version(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(username: 'agent', fullName: 'Agent', email: 'agent@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $handler(new CreateDocumentCommand(project: $project, title: 'Auth PRD', markdown: "# Auth\n\nUse JWTs."));

        self::assertSame(DocumentStatus::InReview, $doc->status);
        self::assertSame(1, $doc->versions->count());
        self::assertStringContainsString('<h1>Auth</h1>', $doc->currentVersion()->renderedHtml);
    }

    public function test_a_rejected_tag_name_leaves_no_document_behind(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(username: 'orphan', fullName: 'Agent', email: 'orphan@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        try {
            $handler(new CreateDocumentCommand(
                project: $project,
                title: 'Auth PRD',
                markdown: '# Auth',
                tagNames: [str_repeat('a', Tag::MAX_NAME_LENGTH + 1)],
            ));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['tags' => 'review.tags.error.too_long'], $e->errors);
        }

        // The caller retries with a shorter tag; without this guarantee the
        // project would end up holding two documents, the first of them
        // unreachable to a caller that only ever saw the error.
        $em->clear();
        $conn = $em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM documents WHERE project_id = :id', ['id' => (string) $project->id]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM tags WHERE project_id = :id', ['id' => (string) $project->id]));
    }
}
