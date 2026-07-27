<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
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
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->persist($replier);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Reply Test Doc', "# Hello\n\nThis is content for the reply test."));

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

    public function test_rejects_a_reply_targeting_a_reply(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'reply-owner2', fullName: 'Reply Owner', email: 'reply-owner2@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Nested Reply Doc', "# Hello\n\nThis is content for the nested reply test."));

        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        $root = $addHandler(new AddCommentCommand($owner, $doc, 'content for the nested', '', '', 'Root comment body'));

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $reply = $replyHandler(new ReplyToCommentCommand(actor: $owner, parent: $root, body: 'First reply'));

        try {
            $replyHandler(new ReplyToCommentCommand(actor: $owner, parent: $reply, body: 'Reply to the reply'));
            self::fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            self::assertSame(['body' => 'comment.error.reply_to_reply'], $e->errors);
        }
    }
}
