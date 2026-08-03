<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Service\CommentExporter;
use App\Module\Review\ValueObject\Anchor;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class CommentExporterTest extends TestCase
{
    public function test_exports_flat_comment_rows_with_thread_and_anchor_context(): void
    {
        $author = new User('alice', 'Alice A', 'alice@example.com', 'x');
        $project = new Project($author, 'My project');
        $document = new Document($author, $project, 'My doc');
        $version = $document->addVersion('# v1', '<h1>v1</h1>');

        $anchor = new Anchor('some quote', 'pre', 'post', 4);
        $parent = new Comment($version, $author, 'parent body', Anchor::unanchored());
        $reply = new Comment($version, $author, 'reply body', $anchor, $parent);
        // Status lives on the thread root; the reply's own value is never set.
        $parent->status = CommentStatus::Resolved;

        /** @var CommentRepository&Stub $repo */
        $repo = $this->createStub(CommentRepository::class);
        $repo->method('findByAuthor')->willReturn([$parent, $reply]);

        $rows = new CommentExporter($repo)->export($author);

        self::assertCount(2, $rows);
        self::assertNull($rows[0]['parentId']);
        self::assertSame((string) $parent->id, $rows[1]['parentId']);
        self::assertSame('My doc', $rows[1]['document']);
        self::assertSame(1, $rows[1]['versionNumber']);
        self::assertSame('reply body', $rows[1]['body']);
        self::assertSame('resolved', $rows[0]['status']);
        self::assertSame('resolved', $rows[1]['status'], 'A reply row reports the status of its thread');
        self::assertFalse($rows[1]['orphaned']);
        self::assertSame([
            'quote' => 'some quote',
            'prefix' => 'pre',
            'suffix' => 'post',
            'offsetHint' => 4,
        ], $rows[1]['anchor']);
        self::assertSame('comments.json', new CommentExporter($repo)->filename());
    }

    public function test_export_keeps_a_strike_distinguishable_from_a_plain_comment(): void
    {
        $author = new User('bob', 'Bob B', 'bob@example.com', 'x');
        $project = new Project($author, 'My project');
        $document = new Document($author, $project, 'My doc');
        $version = $document->addVersion('# v1', '<h1>v1</h1>');

        $anchor = new Anchor('some quote', 'pre', 'post', 4);
        $prose = new Comment($version, $author, 'just asking', $anchor);
        $strike = new Comment($version, $author, '', $anchor, null, '');
        $rewording = new Comment($version, $author, '', $anchor, null, 'better wording');

        /** @var CommentRepository&Stub $repo */
        $repo = $this->createStub(CommentRepository::class);
        $repo->method('findByAuthor')->willReturn([$prose, $strike, $rewording]);

        $rows = new CommentExporter($repo)->export($author);

        // A JSON export that collapsed '' into null would lose which passages the
        // user asked to delete.
        self::assertNull($rows[0]['replacement']);
        self::assertSame('', $rows[1]['replacement']);
        self::assertSame('better wording', $rows[2]['replacement']);
    }
}
