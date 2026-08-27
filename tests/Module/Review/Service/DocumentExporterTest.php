<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\DocumentExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class DocumentExporterTest extends TestCase
{
    public function test_exports_documents_with_nested_versions_and_without_rendered_html(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($user, 'My project');
        $document = new Document($user, $project, 'My doc');
        $document->addVersion('# v1', '<h1>v1</h1>');
        $document->addVersion('# v2', '<h1>v2</h1>');

        /** @var DocumentRepository&Stub $repo */
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findByOwner')->willReturn([$document]);

        $rows = iterator_to_array(new DocumentExporter($repo)->export($user));

        self::assertCount(1, $rows);
        self::assertSame('My doc', $rows[0]['title']);
        self::assertSame('My project', $rows[0]['project']);
        self::assertCount(2, $rows[0]['versions']);
        self::assertSame(1, $rows[0]['versions'][0]['versionNumber']);
        self::assertSame('# v1', $rows[0]['versions'][0]['markdownSource']);
        self::assertArrayNotHasKey('renderedHtml', $rows[0]['versions'][0]);
        self::assertSame('documents.json', new DocumentExporter($repo)->filename());
    }

    public function test_exports_the_tags_a_document_carries(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($user, 'My project');
        $document = new Document($user, $project, 'My doc');
        $document->addVersion('# v1', '<h1>v1</h1>');
        $document->tags->add(new Tag($project, 'Design'));
        $document->tags->add(new Tag($project, 'release'));

        /** @var DocumentRepository&Stub $repo */
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findByOwner')->willReturn([$document]);

        $rows = iterator_to_array(new DocumentExporter($repo)->export($user));

        self::assertSame(['design', 'release'], $rows[0]['tags']);
    }

    public function test_an_untagged_document_exports_an_empty_tag_list(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $document = new Document($user, new Project($user, 'My project'), 'My doc');
        $document->addVersion('# v1', '<h1>v1</h1>');

        /** @var DocumentRepository&Stub $repo */
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findByOwner')->willReturn([$document]);

        self::assertSame([], iterator_to_array(new DocumentExporter($repo)->export($user))[0]['tags']);
    }

    public function test_exports_archive_state_and_version_descriptions(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($user, 'My project');
        $document = new Document($user, $project, 'My doc');
        $document->addVersion('# v1', '<h1>v1</h1>', 'First draft of the auth design.');
        $document->addVersion('# v2', '<h1>v2</h1>');
        $document->archivedAt = new \DateTimeImmutable('2026-08-02 10:00:00');

        /** @var DocumentRepository&Stub $repo */
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findByOwner')->willReturn([$document]);

        $rows = iterator_to_array(new DocumentExporter($repo)->export($user));

        self::assertSame('2026-08-02T10:00:00+00:00', $rows[0]['archivedAt']);
        self::assertSame('First draft of the auth design.', $rows[0]['versions'][0]['description']);
        self::assertNull($rows[0]['versions'][1]['description']);
    }

    public function test_exports_the_documents_a_document_references_and_not_the_ones_referencing_it(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($user, 'My project');
        $document = new Document($user, $project, 'My doc');
        $document->addVersion('# v1', '<h1>v1</h1>');

        $target = new Document($user, $project, 'The spec it answers');
        $document->references->add($target);

        $inbound = new Document($user, $project, 'A thread pointing here');
        $document->referencedBy->add($inbound);

        /** @var DocumentRepository&Stub $repo */
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findByOwner')->willReturn([$document]);

        $rows = iterator_to_array(new DocumentExporter($repo)->export($user));

        self::assertCount(1, $rows[0]['references']);
        self::assertSame('The spec it answers', $rows[0]['references'][0]['title']);
    }

    public function test_a_document_that_was_never_archived_exports_a_null_timestamp(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $document = new Document($user, new Project($user, 'My project'), 'My doc');
        $document->addVersion('# v1', '<h1>v1</h1>');

        /** @var DocumentRepository&Stub $repo */
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findByOwner')->willReturn([$document]);

        $rows = iterator_to_array(new DocumentExporter($repo)->export($user));

        self::assertArrayHasKey('archivedAt', $rows[0]);
        self::assertNull($rows[0]['archivedAt']);
    }
}
