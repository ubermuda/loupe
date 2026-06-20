<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Entity\Comment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReplyToCommentHandlerTest extends KernelTestCase
{
    public function test_creates_reply_with_parent_version_anchor_and_author(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'reply-owner', fullName: 'Reply Owner', email: 'reply-owner@example.com', password: 'hashed');
        $replier = new User(username: 'replier', fullName: 'Replier User', email: 'replier@example.com', password: 'hashed');
        $em->persist($owner);
        $em->persist($replier);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($owner, 'Reply Test Doc', "# Hello\n\nThis is content for the reply test."));

        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        $parent = $addHandler(new AddCommentCommand($owner, $doc, 'content for the reply', '', '', 'Parent comment body'));

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $reply = $replyHandler(new ReplyToCommentCommand(
            actor: $replier,
            parent: $parent,
            body: 'Reply body text',
        ));

        self::assertInstanceOf(Comment::class, $reply);
        self::assertSame($parent, $reply->parent);
        self::assertSame($parent->version, $reply->version);
        self::assertSame($replier, $reply->author);
        self::assertSame($parent->anchor->quote, $reply->anchor->quote);
        self::assertSame($parent->anchor->prefix, $reply->anchor->prefix);
        self::assertSame($parent->anchor->suffix, $reply->anchor->suffix);
        self::assertSame($parent->anchor->offsetHint, $reply->anchor->offsetHint);
        self::assertSame('Reply body text', $reply->body);
    }
}
