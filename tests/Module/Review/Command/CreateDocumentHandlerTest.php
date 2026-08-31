<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateDocumentHandlerTest extends KernelTestCase
{
    public function test_creates_document_with_first_version(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Agent', email: 'agent@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $handler(new CreateDocumentCommand(project: $project, title: 'Auth PRD', markdown: "# Auth\n\nUse JWTs."));

        self::assertSame(DocumentStatus::InReview, $doc->status);
        self::assertSame(1, $doc->versions->count());
        self::assertStringContainsString('<h1 id="heading-auth">Auth</h1>', $doc->currentVersion()->renderedHtml);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedTitles(): iterable
    {
        yield 'blank' => ['', 'review.create.error.blank'];

        yield 'only whitespace' => ['   ', 'review.create.error.blank'];

        yield 'over the maximum length' => [str_repeat('a', 256), 'review.create.error.too_long'];
    }

    /**
     * Creation went without the checks a rename enforces, so a document could be
     * born with a title no rename would ever accept.
     *
     * @param non-empty-string $expectedKey
     */
    #[DataProvider('rejectedTitles')]
    public function test_a_title_a_rename_would_reject_is_rejected_at_creation(string $title, string $expectedKey): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Agent', email: 'title-'.uniqid().'@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $handler = self::getContainer()->get(CreateDocumentHandler::class);

        try {
            $handler(new CreateDocumentCommand(project: $project, title: $title, markdown: '# Hi'));
            self::fail('expected the title to be rejected');
        } catch (DomainErrors $e) {
            self::assertSame(['title' => $expectedKey], $e->errors);
        }
    }

    public function test_a_created_title_is_trimmed(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Agent', email: 'trim-'.uniqid().'@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $handler(new CreateDocumentCommand(project: $project, title: '  Spaced  ', markdown: '# Hi'));

        self::assertSame('Spaced', $doc->title);
    }

    public function test_a_rejected_tag_name_leaves_no_document_behind(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Agent', email: 'orphan@example.com', password: 'hashed-placeholder');
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

    /**
     * The sibling test above proves the handler wrote nothing. This one proves it
     * left nothing for someone else to write: a scheduled insert survives a
     * handler that simply declines to flush, and the next flush on a shared
     * EntityManager — another operation in a long-lived process, or the worker —
     * commits the rejected document on its behalf.
     */
    public function test_a_rejected_tag_name_leaves_nothing_scheduled_for_a_later_flush(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Agent', email: 'unflushed@example.com', password: 'hashed-placeholder');
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
        } catch (DomainErrors) {
        }

        $em->flush();
        $em->clear();

        $conn = $em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM documents WHERE project_id = :id', ['id' => (string) $project->id]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM document_versions'));
    }

    public function test_a_created_document_is_recorded_on_the_domain_channel(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Agent', email: 'create-audit@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $audit = RecordingAuditor::installedIn(self::getContainer());
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        $document = $handler(new CreateDocumentCommand(
            project: $project,
            title: 'Auth PRD',
            markdown: '# Auth',
            tagNames: ['design', 'security'],
        ));

        $record = $audit->record('review.document.created');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $document->id,
            'projectId' => (string) $project->id,
            'tagCount' => 2,
            'referenceCount' => 0,
        ], $record->context);

        // Two records, because the tag set is written by its own handler, which
        // records the write it performs.
        self::assertSame(
            ['review.document.tags_updated', 'review.document.created'],
            $audit->domainLogLines(),
        );
        self::assertSame([], $audit->securityLogLines());
    }

    /** Neither the title nor the body reaches the trail. */
    public function test_the_creation_record_carries_no_document_text(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Agent', email: 'create-audit-text@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $audit = RecordingAuditor::installedIn(self::getContainer());
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        $handler(new CreateDocumentCommand(
            project: $project,
            title: 'Grievance about Dana',
            markdown: '# Dana said',
        ));

        $context = $audit->record('review.document.created')->context;
        self::assertArrayNotHasKey('title', $context);
        self::assertArrayNotHasKey('markdown', $context);
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'Dana'),
        ));
    }

    public function test_a_rejected_creation_records_nothing(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Agent', email: 'create-audit-refused@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $audit = RecordingAuditor::installedIn(self::getContainer());
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        try {
            $handler(new CreateDocumentCommand(project: $project, title: '   ', markdown: '# Auth'));
            self::fail('a blank title must be rejected');
        } catch (DomainErrors) {
        }

        self::assertSame([], $audit->operations());
    }
}
