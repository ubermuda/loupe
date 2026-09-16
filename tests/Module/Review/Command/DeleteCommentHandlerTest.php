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
use App\Module\Review\Command\DeleteCommentCommand;
use App\Module\Review\Command\DeleteCommentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\RestoreCommentCommand;
use App\Module\Review\Command\RestoreCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditEvent;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class DeleteCommentHandlerTest extends KernelTestCase
{
    public function test_retains_comment_and_its_replies_until_explicit_purge(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Delete Owner', email: 'del-owner@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Delete Test Doc', "# Hello\n\nSome content to comment on here."));

        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        $root = $addHandler(new AddCommentCommand($owner, $doc, 1, 'content to comment', '', '', 'Root comment'));

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $reply = $replyHandler(new ReplyToCommentCommand(actor: $owner, parent: $root, body: 'A reply'));
        $rootId = $root->id;
        $replyId = $reply->id;

        $version = $doc->currentVersion();

        /** @var CommentRepository $comments */
        $comments = self::getContainer()->get(CommentRepository::class);
        self::assertCount(2, $comments->findByVersion($version), 'Root + reply exist before delete');

        /** @var DeleteCommentHandler $deleteHandler */
        $deleteHandler = self::getContainer()->get(DeleteCommentHandler::class);
        $deleteHandler(new DeleteCommentCommand(comment: $root));

        self::assertCount(0, $comments->findByVersion($version), 'Deleting the root hides it and its reply');
        $deleteHandler(new DeleteCommentCommand(comment: $root));
        $em->clear();
        $retained = $em->find(Comment::class, $rootId);
        self::assertInstanceOf(Comment::class, $retained);
        self::assertNotNull($retained->deletedAt);
        self::assertSame(1, $retained->deletionSequence);
        self::assertSame('Root comment', $retained->body);
        $retainedReply = $em->find(Comment::class, $replyId);
        self::assertInstanceOf(Comment::class, $retainedReply);
        self::assertTrue($retainedReply->isDeleted);
        self::assertSame('A reply', $retainedReply->body);
    }

    public function test_a_deleted_thread_is_recorded_with_the_ids_it_had(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $document, $root] = $this->seedForAudit('delete-audit@example.com');

        $reply = self::getContainer()->get(ReplyToCommentHandler::class);
        self::assertInstanceOf(ReplyToCommentHandler::class, $reply);
        $reply(new ReplyToCommentCommand(actor: $owner, parent: $root, body: 'A reply'));

        $commentId = (string) $root->id;
        $documentId = (string) $document->id;
        $audit->forget();

        $handler = self::getContainer()->get(DeleteCommentHandler::class);
        self::assertInstanceOf(DeleteCommentHandler::class, $handler);
        $handler(new DeleteCommentCommand(comment: $root));
        $handler(new DeleteCommentCommand(comment: $root));

        $records = $audit->records('review.comment_deleted');
        self::assertCount(2, $records, 'the root and its one reply are each recorded');

        $record = $records[0];
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('comment', $record->subject->type);
        self::assertSame($commentId, $record->subject->id);
        self::assertSame([
            'commentId' => $commentId,
            'documentId' => $documentId,
            'replyCount' => 1,
        ], $record->context);
        self::assertNotSame('', $record->subject->id);

        self::assertSame(
            ['review.comment_deleted', 'review.comment_deleted'],
            $audit->domainLogLines(),
        );
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_every_deleted_reply_gets_its_own_record(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $document, $root] = $this->seedForAudit('delete-audit-replies@example.com');

        $reply = self::getContainer()->get(ReplyToCommentHandler::class);
        self::assertInstanceOf(ReplyToCommentHandler::class, $reply);

        $replyIds = [];
        foreach (['First reply', 'Second reply', 'Third reply'] as $body) {
            $replyIds[] = (string) $reply(new ReplyToCommentCommand(actor: $owner, parent: $root, body: $body))->id;
        }

        $commentId = (string) $root->id;
        $documentId = (string) $document->id;
        $audit->forget();

        $handler = self::getContainer()->get(DeleteCommentHandler::class);
        self::assertInstanceOf(DeleteCommentHandler::class, $handler);
        $handler(new DeleteCommentCommand(comment: $root));

        $records = $audit->records('review.comment_deleted');
        self::assertCount(4, $records, 'one record per deleted comment, root included');

        $subjectIds = array_map(
            static function (AuditEvent $record): string {
                self::assertNotNull($record->subject);
                self::assertSame('comment', $record->subject->type);

                return $record->subject->id;
            },
            $records,
        );

        sort($replyIds);
        $recordedReplyIds = \array_slice($subjectIds, 1);
        sort($recordedReplyIds);

        self::assertSame($commentId, $subjectIds[0]);
        self::assertSame($replyIds, $recordedReplyIds);
        self::assertNotContains('', $subjectIds);

        foreach (\array_slice($records, 1) as $replyRecord) {
            self::assertSame(AuditOutcome::Success, $replyRecord->outcome);
            self::assertSame(Auditor::CATEGORY_DOMAIN, $replyRecord->category);
            self::assertNotNull($replyRecord->subject);
            self::assertSame([
                'commentId' => $replyRecord->subject->id,
                'documentId' => $documentId,
                'parentCommentId' => $commentId,
            ], $replyRecord->context);
        }
    }

    public function test_delete_after_restore_advances_the_sequence_and_refuses_the_old_undo(): void
    {
        self::bootKernel();
        [, , $root] = $this->seedForAudit('delete-cycle@example.com');
        $delete = self::getContainer()->get(DeleteCommentHandler::class);
        $restore = self::getContainer()->get(RestoreCommentHandler::class);
        $delete(new DeleteCommentCommand($root));
        $restore(new RestoreCommentCommand($root, 1));
        $delete(new DeleteCommentCommand($root));
        self::assertSame(2, $root->deletionSequence);
        try {
            $restore(new RestoreCommentCommand($root, 1));
            self::fail('The earlier Undo cannot restore a later deletion.');
        } catch (DomainErrors $error) {
            self::assertContains('comment.error.stale_deletion', $error->errors);
        }
        self::assertTrue($root->isDeleted);
    }

    public function test_a_stale_delete_cannot_change_a_historical_thread(): void
    {
        self::bootKernel();
        [, $document, $root] = $this->seedForAudit('delete-historical@example.com');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $document->addVersion('New content', '<p>New content</p>');
        $em->flush();
        try {
            self::getContainer()->get(DeleteCommentHandler::class)(new DeleteCommentCommand($root));
            self::fail('Historical threads refuse Delete.');
        } catch (DomainErrors $error) {
            self::assertContains('review.document.comment.error.stale_version', $error->errors);
        }
        self::assertTrue($em->isOpen());
        self::assertFalse($root->isDeleted);
        self::assertSame(0, $root->deletionSequence);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{User, Document, Comment}
     */
    private function seedForAudit(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(fullName: 'Owner', email: $email, password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($project, 'Doc', "# Hello\n\nSome content to comment on here."));

        $add = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $add);
        $comment = $add(new AddCommentCommand($owner, $document, 1, 'content to comment', '', '', 'Root comment'));

        return [$owner, $document, $comment];
    }
}
