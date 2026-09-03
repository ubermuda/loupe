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
use App\Module\Review\Entity\Document;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class ReplyToCommentHandlerTest extends KernelTestCase
{
    public function test_creates_reply_with_parent_version_anchor_and_author(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Reply Owner', email: 'reply-owner@example.com', password: 'hashed');
        $replier = new User(fullName: 'Replier User', email: 'replier@example.com', password: 'hashed');
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

        $owner = new User(fullName: 'Reply Owner', email: 'reply-owner2@example.com', password: 'hashed');
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

    public function test_a_reply_is_recorded_on_the_domain_channel(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $document, $parent] = $this->seedForAudit('reply-audit@example.com');
        $audit->forget();

        $handler = self::getContainer()->get(ReplyToCommentHandler::class);
        self::assertInstanceOf(ReplyToCommentHandler::class, $handler);
        $reply = $handler(new ReplyToCommentCommand(actor: $owner, parent: $parent, body: 'Agreed, ask Dana'));

        $record = $audit->record('review.comment_replied');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('comment', $record->subject->type);
        self::assertSame((string) $reply->id, $record->subject->id);
        self::assertSame([
            'commentId' => (string) $reply->id,
            'parentCommentId' => (string) $parent->id,
            'documentId' => (string) $document->id,
        ], $record->context);

        // The reply body is text a person wrote.
        self::assertSame([], array_filter(
            $record->context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'Dana'),
        ));

        self::assertSame(['review.comment_replied'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_a_refused_reply_records_nothing(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, , $parent] = $this->seedForAudit('reply-audit-refused@example.com');
        $audit->forget();

        $handler = self::getContainer()->get(ReplyToCommentHandler::class);
        self::assertInstanceOf(ReplyToCommentHandler::class, $handler);

        try {
            $handler(new ReplyToCommentCommand(actor: $owner, parent: $parent, body: '   '));
            self::fail('an empty reply must be rejected');
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
        $document = $create(new CreateDocumentCommand($project, 'Doc', "# Hello\n\nThis is content for the reply test."));

        $add = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $add);
        $parent = $add(new AddCommentCommand($owner, $document, 'content for the reply', '', '', 'Parent comment body'));

        return [$owner, $document, $parent];
    }
}
