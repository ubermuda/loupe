<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\DocumentExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class DocumentExporterTest extends TestCase
{
    public function test_exports_documents_with_nested_versions_and_without_rendered_html(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');
        $project = new Project($user, 'My project');
        $document = new Document($user, $project, 'My doc');
        $document->addVersion('# v1', '<h1>v1</h1>');
        $document->addVersion('# v2', '<h1>v2</h1>');

        /** @var DocumentRepository&Stub $repo */
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findByOwner')->willReturn([$document]);

        $rows = new DocumentExporter($repo)->export($user);

        self::assertCount(1, $rows);
        self::assertSame('My doc', $rows[0]['title']);
        self::assertSame('My project', $rows[0]['project']);
        self::assertCount(2, $rows[0]['versions']);
        self::assertSame(1, $rows[0]['versions'][0]['versionNumber']);
        self::assertSame('# v1', $rows[0]['versions'][0]['markdownSource']);
        self::assertArrayNotHasKey('renderedHtml', $rows[0]['versions'][0]);
        self::assertSame('documents.json', new DocumentExporter($repo)->filename());
    }
}
