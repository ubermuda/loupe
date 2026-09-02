<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\TagRepository;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SetDocumentTagsHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SetDocumentTagsHandler $handler;
    private TagRepository $tags;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(SetDocumentTagsHandler::class);
        self::assertInstanceOf(SetDocumentTagsHandler::class, $handler);
        $this->handler = $handler;

        $tags = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $tags);
        $this->tags = $tags;
    }

    /** @return array{Project, Document} */
    private function seed(string $slug): array
    {
        $user = new User(fullName: 'U', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.$slug);
        $this->em->persist($project);
        $document = new Document($user, $project, 'doc');
        $document->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return [$project, $document];
    }

    /** @return list<string> */
    private function namesOf(Document $document): array
    {
        $names = [];
        foreach ($document->tags as $tag) {
            $names[] = $tag->name;
        }

        return $names;
    }

    public function test_unknown_names_are_created_lowercased_deduplicated_and_sorted(): void
    {
        [$project, $document] = $this->seed('tags-create');

        $applied = ($this->handler)(new SetDocumentTagsCommand($document, ['Release', 'design', ' DESIGN ', '', '  ']));

        self::assertSame(['design', 'release'], array_map(static fn (Tag $t): string => $t->name, $applied));
        self::assertSame(['design', 'release'], $this->namesOf($document));
        self::assertCount(2, $this->tags->findBy(['project' => $project]));
    }

    public function test_an_existing_project_tag_is_reused_rather_than_duplicated(): void
    {
        [$project, $document] = $this->seed('tags-reuse');
        ($this->handler)(new SetDocumentTagsCommand($document, ['design']));

        $second = new Document($project->owner, $project, 'other doc');
        $second->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($second);
        $this->em->flush();

        ($this->handler)(new SetDocumentTagsCommand($second, ['Design']));

        self::assertCount(1, $this->tags->findBy(['project' => $project]));
        self::assertSame(['design'], $this->namesOf($second));
    }

    public function test_the_same_name_in_two_projects_is_two_rows(): void
    {
        [$firstProject, $firstDocument] = $this->seed('tags-scope-a');
        [$secondProject, $secondDocument] = $this->seed('tags-scope-b');

        ($this->handler)(new SetDocumentTagsCommand($firstDocument, ['design']));
        ($this->handler)(new SetDocumentTagsCommand($secondDocument, ['design']));

        self::assertCount(1, $this->tags->findBy(['project' => $firstProject]));
        self::assertCount(1, $this->tags->findBy(['project' => $secondProject]));
        self::assertNotSame(
            (string) $this->tags->findOneByProjectAndName($firstProject, 'design')?->id,
            (string) $this->tags->findOneByProjectAndName($secondProject, 'design')?->id,
        );
    }

    public function test_the_set_is_replaced_wholesale_without_deleting_the_tag_row(): void
    {
        [$project, $document] = $this->seed('tags-replace');
        ($this->handler)(new SetDocumentTagsCommand($document, ['design', 'release']));

        ($this->handler)(new SetDocumentTagsCommand($document, ['release']));
        self::assertSame(['release'], $this->namesOf($document));

        ($this->handler)(new SetDocumentTagsCommand($document, []));
        self::assertSame([], $this->namesOf($document));

        // The vocabulary outlives the documents that used it.
        self::assertCount(2, $this->tags->findBy(['project' => $project]));
    }

    public function test_replacing_survives_a_reload_in_the_declared_order(): void
    {
        [, $document] = $this->seed('tags-reload');
        ($this->handler)(new SetDocumentTagsCommand($document, ['release', 'design']));
        $documentId = $document->id;
        $this->em->clear();

        $reloaded = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertSame(['design', 'release'], $this->namesOf($reloaded));
    }

    /**
     * Asserted on the join rows rather than the in-memory collection, which
     * would look unchanged whatever the second call emitted. Whether Doctrine
     * re-writes the rows is not asserted — only that the same pairs survive and
     * no tag is duplicated.
     */
    public function test_setting_the_same_set_twice_changes_nothing(): void
    {
        [$project, $document] = $this->seed('tags-idempotent');
        ($this->handler)(new SetDocumentTagsCommand($document, ['design', 'release']));
        $before = $this->joinRowsFor($document);
        self::assertCount(2, $before);

        ($this->handler)(new SetDocumentTagsCommand($document, ['release', 'Design']));

        self::assertSame($before, $this->joinRowsFor($document));
        self::assertSame(['design', 'release'], $this->namesOf($document));
        self::assertCount(2, $this->tags->findBy(['project' => $project]));
    }

    /** @return list<string> the tag ids joined to $document, sorted */
    private function joinRowsFor(Document $document): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT tag_id FROM document_tags WHERE document_id = :id ORDER BY tag_id',
            ['id' => (string) $document->id],
        );

        return $ids;
    }

    /**
     * The find-or-create path means every other test here would still pass with
     * the unique index dropped from the migration. This one asserts the index
     * itself, so regenerating the migration cannot silently lose the invariant
     * the whole design rests on.
     */
    public function test_the_database_rejects_a_duplicate_name_in_one_project(): void
    {
        [$project] = $this->seed('tags-unique');
        $this->em->persist(new Tag($project, 'design'));
        $this->em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO tags (id, project_id, name, created_at) VALUES (:id, :project, :name, NOW())',
            ['id' => (string) Uuid::v7(), 'project' => (string) $project->id, 'name' => 'design'],
        );
    }

    public function test_an_over_long_name_is_rejected_as_a_domain_error(): void
    {
        [$project, $document] = $this->seed('tags-too-long');

        try {
            ($this->handler)(new SetDocumentTagsCommand($document, [str_repeat('a', Tag::MAX_NAME_LENGTH + 1)]));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['tags' => 'review.tags.error.too_long'], $e->errors);
        }

        self::assertSame([], $this->tags->findBy(['project' => $project]));
    }

    public function test_a_tag_set_is_recorded_on_the_domain_channel(): void
    {
        [$project, $document] = $this->seed('tags-audit');

        ($this->handler)(new SetDocumentTagsCommand($document, ['design', 'security']));

        $record = $this->audit->record('review.document.tags_updated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $document->id,
            'projectId' => (string) $project->id,
            'tagCount' => 2,
        ], $record->context);

        self::assertSame(['review.document.tags_updated'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /** A tag name is a phrase a person typed, so the record counts them. */
    public function test_the_record_carries_no_tag_names(): void
    {
        [, $document] = $this->seed('tags-audit-names');

        ($this->handler)(new SetDocumentTagsCommand($document, ['dana-okafor']));

        self::assertSame([], array_filter(
            $this->audit->record('review.document.tags_updated')->context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'dana'),
        ));
    }

    public function test_a_rejected_tag_set_records_nothing(): void
    {
        [, $document] = $this->seed('tags-audit-refused');

        try {
            ($this->handler)(new SetDocumentTagsCommand($document, [str_repeat('a', Tag::MAX_NAME_LENGTH + 1)]));
            self::fail('expected DomainErrors');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
    }
}
