<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use PHPUnit\Framework\TestCase;

final class CommentTest extends TestCase
{
    public function test_comment_defaults_and_reply(): void
    {
        $user = new User(username: 'rev', fullName: 'Rev', email: 'rev@example.com');
        $doc = new Document($user, 'Doc');
        $version = $doc->addVersion('hello world', '<p>hello world</p>');

        $comment = new Comment($version, $user, 'why?', new Anchor('hello', '', ' world', 0));
        self::assertFalse($comment->resolved);
        self::assertFalse($comment->orphaned);
        self::assertNull($comment->parent);

        $reply = new Comment($version, $user, 'because', new Anchor('hello', '', ' world', 0), $comment);
        self::assertSame($comment, $reply->parent);
    }
}
