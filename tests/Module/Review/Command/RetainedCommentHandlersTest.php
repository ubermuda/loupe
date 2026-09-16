<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\MarkCommentAddressedOutcome;
use App\Module\Review\Command\MarkCommentsAddressedCommand;
use App\Module\Review\Command\MarkCommentsAddressedHandler;
use App\Module\Review\Command\PurgeCommentCommand;
use App\Module\Review\Command\PurgeCommentHandler;
use App\Module\Review\Command\ReopenCommentCommand;
use App\Module\Review\Command\ReopenCommentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Command\RestoreCommentCommand;
use App\Module\Review\Command\RestoreCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Direct SQL models stale entity state. DAMA's single connection cannot
// simulate overlapping transactions.
final class RetainedCommentHandlersTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommentRepository $comments;
    private RecordingAuditor $audit;
    private Comment $root;
    private Comment $reply;
    private RestoreCommentHandler $restore;
    private PurgeCommentHandler $purge;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->comments = self::getContainer()->get(CommentRepository::class);
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $this->restore = new RestoreCommentHandler($this->em, $this->comments, $this->audit->auditor);
        $this->purge = new PurgeCommentHandler($this->em, $this->comments, $this->audit->auditor);
        $owner = new User('Thread owner', 'thread-'.uniqid().'@example.com', 'x');
        $project = new Project($owner, 'Thread lifecycle');
        $document = new Document($owner, $project, 'Document');
        $version = $document->addVersion('Original content', '<p>Original content</p>');
        $this->root = new Comment($version, $owner, 'Root body', new Anchor('content', 'Original ', '', 9), replacement: 'replacement');
        $this->reply = new Comment($version, $owner, 'Reply body', $this->root->anchor, $this->root);
        foreach ([$owner, $project, $document, $this->root, $this->reply] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function test_restore_preserves_the_thread_and_does_not_duplicate_audit_success(): void
    {
        $this->root->status = CommentStatus::Resolved;
        $this->retain();
        $rootId = $this->root->id;
        $replyId = $this->reply->id;
        $handler = $this->restore;
        $command = new RestoreCommentCommand($this->root, 1);
        $handler($command);
        $handler($command);
        $this->em->clear();

        $root = $this->em->find(Comment::class, $rootId);
        $reply = $this->em->find(Comment::class, $replyId);
        self::assertInstanceOf(Comment::class, $root);
        self::assertInstanceOf(Comment::class, $reply);
        self::assertNull($root->deletedAt);
        self::assertSame(1, $root->deletionSequence);
        self::assertSame(CommentStatus::Resolved, $root->status);
        self::assertSame('Root body', $root->body);
        self::assertSame('replacement', $root->replacement);
        self::assertSame('content', $root->anchor->quote);
        self::assertSame('Reply body', $reply->body);
        self::assertSame($root, $reply->parent);
        self::assertCount(1, $this->audit->records('review.comment_restored'));
        self::assertSame([
            'commentId' => (string) $rootId,
            'documentId' => (string) $root->version->document->id,
            'versionNumber' => 1,
            'deletionSequence' => 1,
        ], $this->audit->record('review.comment_restored')->context);
    }

    public function test_restore_after_revision_keeps_the_original_version_read_only(): void
    {
        $this->retain();
        $document = $this->root->version->document;
        $newVersion = $document->addVersion('Revised content', '<p>Revised content</p>');
        $this->em->flush();

        ($this->restore)(new RestoreCommentCommand($this->root, 1));

        self::assertSame(1, $this->root->version->versionNumber);
        self::assertCount(2, $this->comments->findByVersion($this->root->version));
        self::assertSame([], $this->comments->findByVersion($newVersion));
        try {
            $this->write('reply');
            self::fail('Historical threads reject new replies.');
        } catch (DomainErrors $error) {
            self::assertSame(['body' => 'review.document.comment.error.stale_version'], $error->errors);
        }
        self::assertTrue($this->em->isOpen());
    }

    public function test_purge_removes_only_the_retained_thread_and_audits_each_row_once(): void
    {
        $this->retain();
        $other = new Comment($this->root->version, $this->root->author, 'Other thread', Anchor::unanchored());
        $this->em->persist($other);
        $this->em->flush();
        $rootId = $this->root->id;
        $replyId = $this->reply->id;
        $otherId = $other->id;
        $handler = $this->purge;
        $command = new PurgeCommentCommand($this->root, 1);
        $handler($command);
        $handler($command);
        $this->em->clear();

        self::assertNull($this->em->find(Comment::class, $rootId));
        self::assertNull($this->em->find(Comment::class, $replyId));
        self::assertNotNull($this->em->find(Comment::class, $otherId));
        $records = $this->audit->records('review.comment_purged');
        self::assertCount(2, $records);
        self::assertSame((string) $rootId, $records[0]->subject?->id);
        self::assertSame((string) $replyId, $records[1]->subject?->id);
        self::assertSame(1, $records[0]->context['replyCount']);
        self::assertStringNotContainsString('Root body', json_encode($records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Reply body', json_encode($records, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('recoveryOperations')]
    public function test_old_forms_cannot_act_on_a_later_deletion(string $operation): void
    {
        $this->retain();
        $this->em->getConnection()->executeStatement('UPDATE comments SET deletion_sequence = 2 WHERE id = :id', ['id' => (string) $this->root->id]);

        try {
            $this->recover($operation, $this->root, 1);
            self::fail('The old deletion sequence must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame(['deletionSequence' => 'comment.error.stale_deletion'], $error->errors);
        }
        self::assertTrue($this->em->isOpen());
        self::assertSame(2, $this->root->deletionSequence);
        self::assertTrue($this->root->isDeleted);
        self::assertCount(2, $this->comments->findDeletedByDocument($this->root->version->document));
        self::assertSame([], $this->audit->operations());
    }

    #[DataProvider('recoveryOperations')]
    public function test_recovery_targets_the_root_not_a_reply(string $operation): void
    {
        $this->retain();
        try {
            $this->recover($operation, $this->reply, 1);
            self::fail('A reply cannot be restored or purged independently.');
        } catch (DomainErrors $error) {
            self::assertSame(['deletionSequence' => 'comment.error.thread_required'], $error->errors);
        }
        self::assertCount(2, $this->comments->findDeletedByDocument($this->root->version->document));
        self::assertSame([], $this->audit->operations());
    }

    public function test_a_stale_purge_form_cannot_remove_a_restored_thread(): void
    {
        $this->retain();
        ($this->restore)(new RestoreCommentCommand($this->root, 1));
        $this->audit->forget();
        try {
            $this->recover('purge', $this->root, 1);
            self::fail('An active thread cannot be purged.');
        } catch (DomainErrors $error) {
            self::assertSame(['deletionSequence' => 'comment.error.not_deleted'], $error->errors);
        }
        self::assertCount(2, $this->comments->findByVersion($this->root->version));
        self::assertSame([], $this->audit->operations());
    }

    #[DataProvider('ordinaryWrites')]
    public function test_writes_read_deletion_state_past_the_identity_map(string $operation): void
    {
        $this->root->status = 'reopen' === $operation ? CommentStatus::Resolved : CommentStatus::Pending;
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE comments SET deleted_at = CURRENT_TIMESTAMP, deletion_sequence = 1 WHERE id = :id', ['id' => (string) $this->root->id]);
        self::assertFalse($this->root->isDeleted);
        try {
            $this->write($operation);
            self::fail('Deleted threads refuse ordinary writes.');
        } catch (DomainErrors $error) {
            self::assertContains('comment.error.deleted', $error->errors);
        }
        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->root->isDeleted);
        self::assertCount(2, $this->comments->findByAuthor($this->root->author));
        self::assertSame([], $this->audit->operations());
    }

    public function test_mark_addressed_refuses_a_deleted_root_even_with_stale_entity_state(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE comments SET deleted_at = CURRENT_TIMESTAMP, deletion_sequence = 1 WHERE id = :id', ['id' => (string) $this->root->id]);
        self::assertFalse($this->comments->markAddressedIfPending($this->root));

        $outcomes = self::getContainer()->get(MarkCommentsAddressedHandler::class)(new MarkCommentsAddressedCommand([$this->root]));

        self::assertSame([MarkCommentAddressedOutcome::Deleted], $outcomes);
        self::assertSame(CommentStatus::Pending, $this->root->status);
        self::assertTrue($this->root->isDeleted);
    }

    #[DataProvider('ordinaryWrites')]
    public function test_ordinary_writes_refuse_a_superseded_version(string $operation): void
    {
        $this->root->status = 'reopen' === $operation ? CommentStatus::Resolved : CommentStatus::Pending;
        $this->root->version->document->addVersion('New version', '<p>New version</p>');
        $this->em->flush();
        $status = $this->root->status;

        try {
            $this->write($operation);
            self::fail('Historical threads refuse ordinary writes.');
        } catch (DomainErrors $error) {
            self::assertContains('review.document.comment.error.stale_version', $error->errors);
        }
        self::assertTrue($this->em->isOpen());
        self::assertSame($status, $this->root->status);
        self::assertCount(2, $this->comments->findByAuthor($this->root->author));
        self::assertSame([], $this->audit->operations());
    }

    /** @return iterable<string, array{string}> */
    public static function recoveryOperations(): iterable
    {
        yield 'restore' => ['restore'];
        yield 'purge' => ['purge'];
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryWrites(): iterable
    {
        yield 'reply' => ['reply'];
        yield 'resolve' => ['resolve'];
        yield 'reopen' => ['reopen'];
    }

    private function retain(): void
    {
        $this->root->deletedAt = new \DateTimeImmutable();
        $this->root->deletionSequence = 1;
        $this->em->flush();
    }

    private function write(string $operation): void
    {
        match ($operation) {
            'reply' => self::getContainer()->get(ReplyToCommentHandler::class)(new ReplyToCommentCommand($this->root->author, $this->root, 'New reply')),
            'resolve' => self::getContainer()->get(ResolveCommentHandler::class)(new ResolveCommentCommand($this->root)),
            'reopen' => self::getContainer()->get(ReopenCommentHandler::class)(new ReopenCommentCommand($this->root)),
            default => throw new \LogicException('Unknown write.'),
        };
    }

    private function recover(string $operation, Comment $comment, int $sequence): void
    {
        match ($operation) {
            'restore' => ($this->restore)(new RestoreCommentCommand($comment, $sequence)),
            'purge' => ($this->purge)(new PurgeCommentCommand($comment, $sequence)),
            default => throw new \LogicException('Unknown recovery action.'),
        };
    }
}
