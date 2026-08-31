<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResolveCommentHandlerTest extends KernelTestCase
{
    public function test_marks_comment_as_resolved(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Resolve Owner', email: 'resolve-owner@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Resolve Test Doc', "# Hello\n\nThis content will be resolved after commenting."));

        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $addHandler(new AddCommentCommand($owner, $doc, 'will be resolved', '', '', 'This needs resolving'));

        self::assertSame(CommentStatus::Pending, $comment->status, 'Comment must start pending');

        /** @var ResolveCommentHandler $resolveHandler */
        $resolveHandler = self::getContainer()->get(ResolveCommentHandler::class);
        $resolveHandler(new ResolveCommentCommand(comment: $comment));

        self::assertSame(CommentStatus::Resolved, $comment->status);
    }

    public function test_resolving_a_thread_closes_it_without_writing_its_replies(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Resolve Owner', email: 'resolve-owner2@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Resolve Thread Doc', "# Hello\n\nThis content will be resolved after commenting."));

        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        $root = $addHandler(new AddCommentCommand($owner, $doc, 'will be resolved', '', '', 'Root comment'));

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $reply = $replyHandler(new ReplyToCommentCommand(actor: $owner, parent: $root, body: 'A reply'));

        self::assertSame(CommentStatus::Pending, $reply->threadStatus, 'The thread must start pending');

        /** @var ResolveCommentHandler $resolveHandler */
        $resolveHandler = self::getContainer()->get(ResolveCommentHandler::class);
        $resolveHandler(new ResolveCommentCommand(comment: $root));

        self::assertSame(CommentStatus::Resolved, $root->status);
        self::assertSame(CommentStatus::Resolved, $reply->threadStatus, 'The whole thread reads as resolved');
        self::assertSame(CommentStatus::Pending, $reply->status, 'The reply row itself is never written');
    }

    public function test_a_reply_is_not_a_resolvable_target(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Resolve Owner', email: 'resolve-owner3@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Resolve Reply Doc', "# Hello\n\nThis content will be resolved after commenting."));

        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        $root = $addHandler(new AddCommentCommand($owner, $doc, 'will be resolved', '', '', 'Root comment'));

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $reply = $replyHandler(new ReplyToCommentCommand(actor: $owner, parent: $root, body: 'A reply'));

        /** @var ResolveCommentHandler $resolveHandler */
        $resolveHandler = self::getContainer()->get(ResolveCommentHandler::class);

        try {
            $resolveHandler(new ResolveCommentCommand(comment: $reply));
            self::fail('Resolving a reply must be rejected');
        } catch (DomainErrors $e) {
            self::assertContains('comment.error.resolve_reply', $e->errors);
        }

        self::assertSame(CommentStatus::Pending, $root->status, 'The thread stays open');
        self::assertSame(CommentStatus::Pending, $reply->status);
    }

    public function test_a_resolved_comment_is_recorded_on_the_domain_channel(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [, $document, $comment] = $this->seedForAudit('resolve-audit@example.com');
        $audit->forget();

        $handler = self::getContainer()->get(ResolveCommentHandler::class);
        self::assertInstanceOf(ResolveCommentHandler::class, $handler);
        $handler(new ResolveCommentCommand(comment: $comment));

        $record = $audit->record('review.comment.resolved');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame([
            'commentId' => (string) $comment->id,
            'documentId' => (string) $document->id,
        ], $record->context);

        self::assertSame(['review.comment.resolved'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_resolving_a_reply_records_nothing(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, , $root] = $this->seedForAudit('resolve-audit-reply@example.com');

        $reply = self::getContainer()->get(ReplyToCommentHandler::class);
        self::assertInstanceOf(ReplyToCommentHandler::class, $reply);
        $child = $reply(new ReplyToCommentCommand(actor: $owner, parent: $root, body: 'A reply'));
        $audit->forget();

        $handler = self::getContainer()->get(ResolveCommentHandler::class);
        self::assertInstanceOf(ResolveCommentHandler::class, $handler);

        try {
            $handler(new ResolveCommentCommand(comment: $child));
            self::fail('a reply must not be resolvable');
        } catch (DomainErrors) {
        }

        self::assertSame([], $audit->operations());
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
