<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\DeleteCommentCommand;
use App\Module\Review\Command\DeleteCommentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteCommentHandlerTest extends KernelTestCase
{
    public function test_deletes_comment_and_its_replies(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'del-owner', fullName: 'Delete Owner', email: 'del-owner@example.com', password: 'hashed');
        $em->persist($owner);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($owner, 'Delete Test Doc', "# Hello\n\nSome content to comment on here."));

        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        $root = $addHandler(new AddCommentCommand($owner, $doc, 'content to comment', '', '', 'Root comment'));

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $replyHandler(new ReplyToCommentCommand(actor: $owner, parent: $root, body: 'A reply'));

        $version = $doc->currentVersion();

        /** @var CommentRepository $comments */
        $comments = self::getContainer()->get(CommentRepository::class);
        self::assertCount(2, $comments->findByVersion($version), 'Root + reply exist before delete');

        /** @var DeleteCommentHandler $deleteHandler */
        $deleteHandler = self::getContainer()->get(DeleteCommentHandler::class);
        $deleteHandler(new DeleteCommentCommand(comment: $root));

        self::assertCount(0, $comments->findByVersion($version), 'Deleting the root removes it and its reply');
    }
}
