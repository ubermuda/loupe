<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\DeleteCommentCommand;
use App\Module\Review\Command\DeleteCommentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteCommentHandlerTest extends KernelTestCase
{
    public function test_deletes_comment_and_its_replies(): void
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

    /**
     * The ids are read before the flush: Doctrine nulls a removed entity's
     * identifier, so a record built afterwards would carry empty strings.
     */
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

        $record = $audit->record('review.comment.deleted');
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

        self::assertSame(['review.comment.deleted'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
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
        $comment = $add(new AddCommentCommand($owner, $document, 'content to comment', '', '', 'Root comment'));

        return [$owner, $document, $comment];
    }
}
