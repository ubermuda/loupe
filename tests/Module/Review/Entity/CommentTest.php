<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use PHPUnit\Framework\TestCase;

final class CommentTest extends TestCase
{
    public function test_comment_defaults_and_reply(): void
    {
        $user = new User(fullName: 'Rev', email: 'rev@example.com');
        $doc = new Document(owner: $user, project: new Project($user, 'p'), title: 'Doc');
        $version = $doc->addVersion('hello world', '<p>hello world</p>');

        $comment = new Comment($version, $user, 'why?', new Anchor('hello', '', ' world', 0));
        self::assertSame(CommentStatus::Pending, $comment->status);
        self::assertFalse($comment->orphaned);
        self::assertNull($comment->parent);

        $reply = new Comment($version, $user, 'because', new Anchor('hello', '', ' world', 0), $comment);
        self::assertSame($comment, $reply->parent);
    }

    public function test_a_reply_reads_the_status_of_its_thread_root(): void
    {
        $user = new User(fullName: 'Rev', email: 'rev2@example.com');
        $doc = new Document(owner: $user, project: new Project($user, 'p'), title: 'Doc');
        $version = $doc->addVersion('hello world', '<p>hello world</p>');

        $root = new Comment($version, $user, 'why?', new Anchor('hello', '', ' world', 0));
        $reply = new Comment($version, $user, 'because', new Anchor('hello', '', ' world', 0), $root);

        $root->status = CommentStatus::Resolved;

        self::assertSame(CommentStatus::Resolved, $root->threadStatus);
        self::assertSame(CommentStatus::Resolved, $reply->threadStatus, 'A reply reports its thread root status, not its own');
        self::assertSame(CommentStatus::Pending, $reply->status, 'A reply keeps its own untouched default');
    }
}
