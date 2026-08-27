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
use App\Module\Review\Command\ReopenCommentCommand;
use App\Module\Review\Command\ReopenCommentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReopenCommentHandlerTest extends KernelTestCase
{
    /** @return array{User, Document} */
    private function createOwnerAndDocument(EntityManagerInterface $em, string $suffix): array
    {
        $owner = new User(fullName: 'Reopen Owner', email: 'reopen-owner'.$suffix.'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Reopen Test Doc', "# Hello\n\nThis content will be resolved after commenting."));

        return [$owner, $doc];
    }

    private function addRootComment(User $owner, Document $doc): Comment
    {
        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);

        return $addHandler(new AddCommentCommand($owner, $doc, 'will be resolved', '', '', 'This needs resolving'));
    }

    public function test_a_resolved_thread_goes_back_to_pending(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $owner */
        /** @var Document $doc */
        [$owner, $doc] = $this->createOwnerAndDocument($em, '1');
        $root = $this->addRootComment($owner, $doc);

        /** @var ResolveCommentHandler $resolve */
        $resolve = self::getContainer()->get(ResolveCommentHandler::class);
        $resolve(new ResolveCommentCommand(comment: $root));
        self::assertSame(CommentStatus::Resolved, $root->status);

        /** @var ReopenCommentHandler $reopen */
        $reopen = self::getContainer()->get(ReopenCommentHandler::class);
        $reopen(new ReopenCommentCommand(comment: $root));

        self::assertSame(CommentStatus::Pending, $root->status);

        $commentId = $root->id;
        self::assertNotNull($commentId);
        $em->clear();
        $fresh = $em->find(Comment::class, $commentId);
        self::assertInstanceOf(Comment::class, $fresh);
        self::assertSame(CommentStatus::Pending, $fresh->status, 'The reopen is written, not just held in memory');
    }

    public function test_an_open_thread_cannot_be_reopened(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $owner */
        /** @var Document $doc */
        [$owner, $doc] = $this->createOwnerAndDocument($em, '2');
        $root = $this->addRootComment($owner, $doc);

        /** @var ReopenCommentHandler $reopen */
        $reopen = self::getContainer()->get(ReopenCommentHandler::class);

        try {
            $reopen(new ReopenCommentCommand(comment: $root));
            self::fail('Reopening an already-open thread must be rejected');
        } catch (DomainErrors $e) {
            self::assertContains('comment.error.reopen_not_resolved', $e->errors);
        }

        self::assertSame(CommentStatus::Pending, $root->status);
    }

    public function test_a_reply_is_not_a_reopenable_target(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $owner */
        /** @var Document $doc */
        [$owner, $doc] = $this->createOwnerAndDocument($em, '3');
        $root = $this->addRootComment($owner, $doc);

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $reply = $replyHandler(new ReplyToCommentCommand(actor: $owner, parent: $root, body: 'A reply'));

        /** @var ResolveCommentHandler $resolve */
        $resolve = self::getContainer()->get(ResolveCommentHandler::class);
        $resolve(new ResolveCommentCommand(comment: $root));

        /** @var ReopenCommentHandler $reopen */
        $reopen = self::getContainer()->get(ReopenCommentHandler::class);

        try {
            $reopen(new ReopenCommentCommand(comment: $reply));
            self::fail('Reopening a reply must be rejected');
        } catch (DomainErrors $e) {
            self::assertContains('comment.error.reopen_reply', $e->errors);
        }

        self::assertSame(CommentStatus::Resolved, $root->status, 'The thread stays closed');
    }
}
