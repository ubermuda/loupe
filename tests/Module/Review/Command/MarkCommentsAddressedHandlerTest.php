<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Audit\AuditEvent;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\MarkCommentAddressedOutcome;
use App\Module\Review\Command\MarkCommentsAddressedCommand;
use App\Module\Review\Command\MarkCommentsAddressedHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarkCommentsAddressedHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MarkCommentsAddressedHandler $handler;
    private RecordingAuditor $audit;
    private User $owner;
    private Document $document;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(MarkCommentsAddressedHandler::class);
        self::assertInstanceOf(MarkCommentsAddressedHandler::class, $handler);
        $this->handler = $handler;

        $this->owner = new User(fullName: 'Owner', email: 'mark-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($this->owner);
        $project = new Project($this->owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $this->document = $create(new CreateDocumentCommand(
            $project,
            'Doc',
            "# Hello\n\nSome content to comment on here, and more of it below.",
        ));

        $this->audit->forget();
    }

    private function comment(string $quote = 'content to comment'): Comment
    {
        $add = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $add);
        $comment = $add(new AddCommentCommand($this->owner, $this->document, $quote, '', '', 'Please fix'));
        $this->audit->forget();

        return $comment;
    }

    public function test_a_pending_root_comment_becomes_addressed(): void
    {
        $comment = $this->comment();

        $outcomes = ($this->handler)(new MarkCommentsAddressedCommand([$comment]));

        self::assertSame([MarkCommentAddressedOutcome::Addressed], $outcomes);
        self::assertSame(CommentStatus::Addressed->value, $this->em->getConnection()->fetchOne(
            'SELECT status FROM comments WHERE id = :id',
            ['id' => (string) $comment->id],
        ));
    }

    public function test_an_addressed_comment_is_recorded_on_the_domain_channel(): void
    {
        $comment = $this->comment();

        ($this->handler)(new MarkCommentsAddressedCommand([$comment]));

        $record = $this->audit->record('review.comment.addressed');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame([
            'commentId' => (string) $comment->id,
            'documentId' => (string) $this->document->id,
            'result' => 'Addressed',
        ], $record->context);

        self::assertSame(['review.comment.addressed'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /**
     * The record that a decorator could not produce. A decorator sees one call
     * and one return value, so it can write one record; the batch decides six
     * different things and needs one record for each.
     */
    public function test_a_batch_of_three_writes_three_records_and_not_a_summary(): void
    {
        $first = $this->comment('content to comment');
        $second = $this->comment('more of it below');
        $third = $this->comment('Hello');

        ($this->handler)(new MarkCommentsAddressedCommand([$first, $second, $third]));

        $records = $this->audit->records('review.comment.addressed');
        self::assertCount(3, $records);
        self::assertSame(
            [(string) $first->id, (string) $second->id, (string) $third->id],
            array_map(static fn (AuditEvent $event): string => (string) ($event->context['commentId'] ?? ''), $records),
        );
        self::assertSame(
            [AuditOutcome::Success, AuditOutcome::Success, AuditOutcome::Success],
            array_map(static fn (AuditEvent $event): AuditOutcome => $event->outcome, $records),
        );
    }

    /**
     * A refusal is not a summary line either: each of the three comments below
     * is declined for its own reason, and the record says which.
     */
    public function test_a_mixed_batch_records_the_outcome_of_every_comment(): void
    {
        $addressable = $this->comment('content to comment');

        $resolved = $this->comment('more of it below');
        $resolve = self::getContainer()->get(ResolveCommentHandler::class);
        self::assertInstanceOf(ResolveCommentHandler::class, $resolve);
        $resolve(new ResolveCommentCommand(comment: $resolved));

        $root = $this->comment('Hello');
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        self::assertInstanceOf(ReplyToCommentHandler::class, $replyHandler);
        $reply = $replyHandler(new ReplyToCommentCommand(actor: $this->owner, parent: $root, body: 'A reply'));

        $alreadyAddressed = $this->comment('Some content');
        ($this->handler)(new MarkCommentsAddressedCommand([$alreadyAddressed]));

        $this->audit->forget();

        $outcomes = ($this->handler)(new MarkCommentsAddressedCommand([
            $addressable,
            $resolved,
            $reply,
            $alreadyAddressed,
        ]));

        self::assertSame([
            MarkCommentAddressedOutcome::Addressed,
            MarkCommentAddressedOutcome::AlreadyResolved,
            MarkCommentAddressedOutcome::IsReply,
            MarkCommentAddressedOutcome::AlreadyAddressed,
        ], $outcomes);

        $records = $this->audit->records('review.comment.addressed');
        self::assertCount(4, $records);
        self::assertSame(
            [AuditOutcome::Success, AuditOutcome::Refused, AuditOutcome::Refused, AuditOutcome::Refused],
            array_map(static fn (AuditEvent $event): AuditOutcome => $event->outcome, $records),
        );
        self::assertSame(
            ['Addressed', 'AlreadyResolved', 'IsReply', 'AlreadyAddressed'],
            array_map(static fn (AuditEvent $event): string => (string) $event->context['result'], $records),
        );
        self::assertSame(
            [(string) $addressable->id, (string) $resolved->id, (string) $reply->id, (string) $alreadyAddressed->id],
            array_map(static fn (AuditEvent $event): string => (string) $event->context['commentId'], $records),
        );
    }

    /** A comment left behind by a revision is refused, and says so. */
    public function test_a_superseded_comment_is_recorded_as_refused(): void
    {
        $comment = $this->comment();

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($this->document, '# Hello again', 'rewritten'));
        $this->audit->forget();

        $outcomes = ($this->handler)(new MarkCommentsAddressedCommand([$comment]));

        self::assertSame([MarkCommentAddressedOutcome::Superseded], $outcomes);
        $record = $this->audit->record('review.comment.addressed');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame('Superseded', $record->context['result']);
    }

    public function test_an_empty_batch_records_nothing(): void
    {
        ($this->handler)(new MarkCommentsAddressedCommand([]));

        self::assertSame([], $this->audit->operations());
    }

    /** The comment body never reaches the trail. */
    public function test_the_records_carry_no_comment_text(): void
    {
        $comment = $this->comment();

        ($this->handler)(new MarkCommentsAddressedCommand([$comment]));

        $context = $this->audit->record('review.comment.addressed')->context;
        self::assertArrayNotHasKey('body', $context);
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'Please fix'),
        ));
    }

    /**
     * The MCP tool accepts a list of ids and resolves each occurrence to the
     * same row, so the same comment can reach the batch twice. Deciding it
     * twice addressed it and then found it already addressed, which put two
     * disagreeing records against one comment.
     */
    public function test_the_same_comment_named_twice_is_decided_and_recorded_once(): void
    {
        $comment = $this->comment();

        $outcomes = ($this->handler)(new MarkCommentsAddressedCommand([$comment, $comment]));

        self::assertCount(1, $this->audit->records('review.comment.addressed'));
        self::assertSame(
            [MarkCommentAddressedOutcome::Addressed, MarkCommentAddressedOutcome::Addressed],
            $outcomes,
        );
        $record = $this->audit->record('review.comment.addressed');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame('Addressed', $record->context['result']);
        self::assertSame((string) $comment->id, $record->subject?->id);
    }

    /** Every occurrence gets the one answer, so the caller reads no contradiction either. */
    public function test_a_repeated_comment_returns_the_same_outcome_for_every_occurrence(): void
    {
        $comment = $this->comment();
        $other = $this->comment('more of it below');

        $outcomes = ($this->handler)(new MarkCommentsAddressedCommand([$comment, $other, $comment]));

        self::assertSame([
            MarkCommentAddressedOutcome::Addressed,
            MarkCommentAddressedOutcome::Addressed,
            MarkCommentAddressedOutcome::Addressed,
        ], $outcomes);
        self::assertCount(2, $this->audit->records('review.comment.addressed'));
    }

    /** A repeat of a comment the batch refuses is refused once, not twice. */
    public function test_a_repeated_refusal_is_recorded_once(): void
    {
        $root = $this->comment();
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        self::assertInstanceOf(ReplyToCommentHandler::class, $replyHandler);
        $reply = $replyHandler(new ReplyToCommentCommand(actor: $this->owner, parent: $root, body: 'A reply'));
        $this->audit->forget();

        $outcomes = ($this->handler)(new MarkCommentsAddressedCommand([$reply, $reply]));

        self::assertSame(
            [MarkCommentAddressedOutcome::IsReply, MarkCommentAddressedOutcome::IsReply],
            $outcomes,
        );
        $records = $this->audit->records('review.comment.addressed');
        self::assertCount(1, $records);
        self::assertSame(AuditOutcome::Refused, $records[0]->outcome);
        self::assertSame('IsReply', $records[0]->context['result']);
    }
}
