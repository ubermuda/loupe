<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Audit\AuditContext;
use App\Exception\DomainErrors;
use App\Module\Account\Command\DeleteAccountCommand;
use App\Module\Account\Command\DeleteAccountHandler;
use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Highlight;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\ValueObject\Anchor;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\NullAuditActorProvider;

final class DeleteAccountHandlerTest extends KernelTestCase
{
    public const string ORPHANED_ARCHIVE_KEY = 'exports/orphaned-archive.zip';

    public function test_invalid_token_throws_and_deletes_nothing(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User('Del X', 'del-x@example.com', 'hash');
        $em->persist($user);
        $em->flush();
        $userId = $user->id;

        $handler = self::getContainer()->get(DeleteAccountHandler::class);
        self::assertInstanceOf(DeleteAccountHandler::class, $handler);

        try {
            $handler(new DeleteAccountCommand('not-a-token'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['token' => 'account.delete.error.invalid_token'], $e->errors);
        }

        $em->clear();
        self::assertNotNull($em->find(User::class, $userId));
    }

    /**
     * Two records, and the pair is the point: `account.deleted` names the
     * account the purger removed, while only `account.deletion_confirmed` says
     * the owner clicked the emailed link themselves.
     */
    public function test_a_confirmed_deletion_records_both_the_confirmation_and_the_deletion(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User('Del Audit', 'del-audit@example.com', 'hash');
        $em->persist($user);
        $token = $user->generateAccountDeletionToken();
        $em->flush();
        $userId = (string) $user->id;

        $handler = self::getContainer()->get(DeleteAccountHandler::class);
        self::assertInstanceOf(DeleteAccountHandler::class, $handler);
        $handler(new DeleteAccountCommand($token));

        self::assertSame(['account.deleted', 'account.deletion_confirmed'], $audit->operations());

        foreach (['account.deleted', 'account.deletion_confirmed'] as $operation) {
            $record = $audit->record($operation);
            self::assertSame(AuditOutcome::Success, $record->outcome);
            self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
            self::assertSame(['userId' => $userId], $record->context);
            self::assertNotNull($record->subject);
            self::assertSame('user', $record->subject->type);
            self::assertSame($userId, $record->subject->id);
        }

        self::assertSame(['account.deleted', 'account.deletion_confirmed'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    /**
     * The storage key is the one thing the record drops, so the purger keeps a
     * logger for it and nothing else.
     */
    public function test_only_the_orphaned_key_is_logged_beside_the_auditor(): void
    {
        $user = new User('Del Split', 'del-split@example.com', 'hash');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v4());

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects($this->once())->method('delete')
            ->willThrowException(new UnableToDeleteFile('bucket unreachable'));

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $logger = new RecordingLogger();
        $this->purgerWith($audit, $storage, [$this->archiveDeletingPurger()], $logger)->purge($user);

        DirectLogging::assertDiagnosticsLoggedBeside($audit, $logger, 'account.deletion_archive_unlink_failed', ['key']);
        DirectLogging::assertOperationNotLoggedBy($audit, $logger, 'account.deleted');
        self::assertSame(self::ORPHANED_ARCHIVE_KEY, $logger->records[0]['context']['key'] ?? null);
    }

    /**
     * The archive outlives the account it belonged to, which is the one thing an
     * erasure trail must not leave unsaid. The storage key names the orphan and
     * is a path, so the record points at the account instead.
     */
    public function test_an_archive_that_could_not_be_unlinked_records_a_failure(): void
    {
        $user = new User('Del Archive', 'del-archive@example.com', 'hash');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v4());
        $userId = (string) $user->id;

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects($this->once())->method('delete')
            ->with(self::ORPHANED_ARCHIVE_KEY)
            ->willThrowException(new UnableToDeleteFile('bucket unreachable'));

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->purgerWith($audit, $storage, [$this->archiveDeletingPurger()])->purge($user);

        self::assertSame(
            ['account.deletion_archive_unlink_failed', 'account.deleted'],
            $audit->operations(),
        );

        $record = $audit->record('account.deletion_archive_unlink_failed');
        self::assertSame(AuditOutcome::Failed, $record->outcome);
        self::assertSame(['userId' => $userId], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame($userId, $record->subject->id);
        self::assertStringNotContainsString(
            self::ORPHANED_ARCHIVE_KEY,
            json_encode($audit->domainChannel->records, \JSON_THROW_ON_ERROR),
        );
    }

    /** A deletion whose archives all went cleanly says nothing extra. */
    public function test_a_clean_unlink_records_only_the_deletion(): void
    {
        $user = new User('Del Clean', 'del-clean@example.com', 'hash');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v4());

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects($this->once())->method('delete');

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->purgerWith($audit, $storage, [$this->archiveDeletingPurger()])->purge($user);

        self::assertSame(['account.deleted'], $audit->operations());
    }

    /** Schedules one archive deletion, so the purger's post-commit unlink runs. */
    private function archiveDeletingPurger(): AccountDataPurgerInterface
    {
        return new class implements AccountDataPurgerInterface {
            #[\Override]
            public function deletionOrder(): int
            {
                return 1;
            }

            #[\Override]
            public function purge(User $user, AccountDeletionCleanup $cleanup): void
            {
                $cleanup->scheduleArchiveDeletion(DeleteAccountHandlerTest::ORPHANED_ARCHIVE_KEY);
            }
        };
    }

    /**
     * @param list<AccountDataPurgerInterface> $purgers
     */
    private function purgerWith(RecordingAuditor $audit, FilesystemOperator $storage, array $purgers, ?RecordingLogger $logger = null): AccountPurger
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));

        return new AccountPurger(
            $this->createStub(MessageBusInterface::class),
            $em,
            $logger ?? new RecordingLogger(),
            $audit->auditor,
            new AuditContext(),
            $storage,
            $purgers,
            [],
        );
    }

    /** An invalid token deletes nothing, so it must state nothing either. */
    public function test_an_invalid_token_records_nothing(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $handler = self::getContainer()->get(DeleteAccountHandler::class);
        self::assertInstanceOf(DeleteAccountHandler::class, $handler);

        try {
            $handler(new DeleteAccountCommand('not-a-token'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors) {
        }

        self::assertSame([], $audit->operations());
    }

    public function test_valid_token_deletes_the_full_owned_graph_and_spares_other_users(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $fixture = $this->seedFullGraph($em);
        $owner = $fixture['owner'];
        $token = $owner->generateAccountDeletionToken();
        $em->flush();
        $ownerId = $owner->id;

        $storage = self::getContainer()->get('test.export.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);
        $archiveKey = DataExport::computeArchiveKey($fixture['exportId']);
        self::assertTrue($storage->fileExists($archiveKey));

        $handler = self::getContainer()->get(DeleteAccountHandler::class);
        self::assertInstanceOf(DeleteAccountHandler::class, $handler);
        $handler(new DeleteAccountCommand($token));

        $em->clear();
        self::assertNull($em->find(User::class, $ownerId));
        self::assertFalse($storage->fileExists($archiveKey));

        // The cancellation message is recorded inside the deletion
        // transaction (see the rollback-safety unit test below for proof a
        // failed transaction never enqueues it) and becomes visible to the
        // worker only once that transaction commits. Actual Stripe
        // cancellation is exercised by CancelSubscriptionHandlerTest.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        $cancelMessages = array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $sent),
            static fn ($m) => $m instanceof CancelSubscriptionMessage,
        ));
        self::assertCount(1, $cancelMessages);
        self::assertSame('sub_delete_me', $cancelMessages[0]->stripeSubscriptionId);
        self::assertSame('cus_delete_me', $cancelMessages[0]->stripeCustomerId);
        self::assertSame((string) $ownerId, $cancelMessages[0]->deletedUserId);

        $conn = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $conn);
        foreach ([
            'users' => 'id',
            'projects' => 'owner_id',
            'documents' => 'owner_id',
            'comments' => 'author_id',
            'reviews' => 'reviewer_id',
            'api_tokens' => 'owner_id',
            'connected_accounts' => 'user_id',
            'data_exports' => 'user_id',
            'billing_profiles' => 'user_id',
        ] as $table => $column) {
            self::assertSame(
                0,
                (int) $conn->fetchOne(sprintf('SELECT count(*) FROM %s WHERE %s = :id', $table, $column), ['id' => (string) $ownerId]),
                sprintf('%s still has rows for the deleted user', $table),
            );
        }

        // The descendant reply, authored by someone else, is gone too — it's
        // a child of the deleted user's own comment.
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM comments WHERE id = :id', ['id' => (string) $fixture['replyToOwnerCommentId']]));
        // The grandchild reply survives only because it too was a descendant
        // of the deleted user's comment — confirms the walk goes more than
        // one level deep.
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM comments WHERE id = :id', ['id' => (string) $fixture['grandchildReplyId']]));
        // The foreign-owned document (schema-permitted owner != project.owner)
        // and everything under it — including a comment authored by someone
        // else — is gone too.
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM documents WHERE id = :id', ['id' => (string) $fixture['foreignDocumentId']]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM comments WHERE id = :id', ['id' => (string) $fixture['foreignDocumentCommentId']]));
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT count(*) FROM document_highlights h JOIN document_versions v ON h.version_id = v.id WHERE v.document_id = :id',
            ['id' => (string) $fixture['foreignDocumentId']],
        ));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM document_references WHERE target_document_id = :id', ['id' => (string) $fixture['foreignDocumentId']]));

        // Two of the owner's own projects were both fully torn down.
        foreach (['doomedProject1Id', 'doomedProject2Id'] as $key) {
            self::assertNull($em->find(Project::class, $fixture[$key]));
        }
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM document_versions WHERE document_id = :id', ['id' => (string) $fixture['foreignDocumentId']]));
        // Asserted on the document id rather than through a join, so it cannot
        // pass merely because the document row is already gone.
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM document_tags WHERE document_id = :id', ['id' => (string) $fixture['foreignDocumentId']]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM decision_selections WHERE document_id = :id', ['id' => (string) $fixture['foreignDocumentId']]));

        // Nothing belonging to the other, untouched users was removed: their
        // own project, their comment authored on the foreign document, and
        // their account all survive.
        $other = $em->find(User::class, $fixture['otherId']);
        self::assertNotNull($other);
        $spared = $em->find(Project::class, $fixture['otherProjectId']);
        self::assertNotNull($spared);
        self::assertNotNull($em->find(User::class, $fixture['thirdId']));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM comments WHERE id = :id', ['id' => (string) $fixture['otherAuthoredCommentId']]));
    }

    /**
     * A failure that prevents the transaction from ever running must leave
     * Stripe untouched: the cancellation message is dispatched from inside
     * wrapInTransaction()'s closure, so if wrapInTransaction() itself never
     * invokes that closure, dispatch() is never called. This is a pure unit
     * test (test doubles for every collaborator, no kernel) proving that
     * sequencing directly, rather than trying to force a genuine Doctrine
     * rollback — the same honesty as the "Concurrency" note in
     * project-backend: the guarantee is verified by code shape here, not by
     * exercising a real rollback (which would additionally require the
     * `async` transport to be Doctrine-backed rather than the test
     * environment's in-memory:// override).
     */
    public function test_transaction_failure_leaves_stripe_cancellation_undispatched(): void
    {
        $user = new User('Del Rollback', 'del-rollback@example.com', 'hash');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v4());
        $token = $user->generateAccountDeletionToken();

        $users = $this->createStub(UserRepository::class);
        $users->method('findByAccountDeletionToken')->willReturn($user);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException(new \RuntimeException('simulated transaction failure'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $handler = new DeleteAccountHandler(
            $users,
            new AccountPurger($bus, $em, new RecordingLogger(), $audit->auditor, new AuditContext(), $this->createStub(FilesystemOperator::class), [], []),
            $audit->auditor,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated transaction failure');
        $handler(new DeleteAccountCommand($token));
    }

    /** Purgers are invoked in ascending deletionOrder(), never registration order. */
    public function test_purgers_run_in_ascending_deletion_order(): void
    {
        $user = new User('Del Order', 'del-order@example.com', 'hash');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v4());
        $token = $user->generateAccountDeletionToken();

        $users = $this->createStub(UserRepository::class);
        $users->method('findByAccountDeletionToken')->willReturn($user);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $connection = $this->createStub(Connection::class);
        $em->method('getConnection')->willReturn($connection);

        /** @var \ArrayObject<int, int> $calls */
        $calls = new \ArrayObject();
        $makePurger = static fn (int $order): AccountDataPurgerInterface => new class($order, $calls) implements AccountDataPurgerInterface {
            /** @param \ArrayObject<int, int> $calls */
            public function __construct(
                private readonly int $order,
                private \ArrayObject $calls,
            ) {
            }

            #[\Override]
            public function deletionOrder(): int
            {
                return $this->order;
            }

            #[\Override]
            public function purge(User $user, AccountDeletionCleanup $cleanup): void
            {
                $this->calls[] = $this->order;
            }
        };

        $purgers = [$makePurger(80), $makePurger(10), $makePurger(30)];

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $handler = new DeleteAccountHandler(
            $users,
            new AccountPurger($this->createStub(MessageBusInterface::class), $em, new RecordingLogger(), $audit->auditor, new AuditContext(), $this->createStub(FilesystemOperator::class), $purgers, []),
            $audit->auditor,
        );
        $handler(new DeleteAccountCommand($token));

        self::assertSame([10, 30, 80], $calls->getArrayCopy());
    }

    /**
     * @return array{
     *     owner: User,
     *     otherId: Uuid,
     *     thirdId: Uuid,
     *     exportId: Uuid,
     *     doomedProject1Id: Uuid,
     *     doomedProject2Id: Uuid,
     *     otherProjectId: Uuid,
     *     replyToOwnerCommentId: Uuid,
     *     grandchildReplyId: Uuid,
     *     foreignDocumentId: Uuid,
     *     foreignDocumentCommentId: Uuid,
     *     otherAuthoredCommentId: Uuid,
     * }
     */
    private function seedFullGraph(EntityManagerInterface $em): array
    {
        $owner = $this->makeUser($em, 'deleter-owner');
        $other = $this->makeUser($em, 'deleter-other');
        $third = $this->makeUser($em, 'deleter-third');
        $em->flush();

        // Two of the owner's own projects, each with a full document subtree —
        // exercises ProjectDeleter running twice in the same transaction.
        $doomed1 = $this->seedOwnedProject($em, $owner, 'del-doomed-1');
        $doomed2 = $this->seedOwnedProject($em, $owner, 'del-doomed-2');

        // Another user's project the owner does NOT own, but was invited to
        // review: the owner authors a root comment, the other user replies,
        // and a third user replies to that reply — a two-level descendant
        // chain, by two different authors, hanging off the deleted user's
        // own comment.
        $otherProject = new Project(owner: $other, name: 'del-other-project');
        $em->persist($otherProject);
        $otherDocument = new Document(owner: $other, project: $otherProject, title: 'other doc');
        $em->persist($otherDocument);
        $otherVersion = $otherDocument->addVersion('# Other', '<h1>Other</h1>');

        $ownerComment = new Comment(version: $otherVersion, author: $owner, body: 'owner review comment', anchor: Anchor::unanchored());
        $em->persist($ownerComment);
        $replyToOwner = new Comment(version: $otherVersion, author: $other, body: 'reply by project owner', anchor: Anchor::unanchored(), parent: $ownerComment);
        $em->persist($replyToOwner);
        $grandchildReply = new Comment(version: $otherVersion, author: $third, body: 'grandchild reply', anchor: Anchor::unanchored(), parent: $replyToOwner);
        $em->persist($grandchildReply);
        $otherAuthoredComment = new Comment(version: $otherVersion, author: $other, body: 'unrelated comment by project owner', anchor: Anchor::unanchored());
        $em->persist($otherAuthoredComment);

        $em->persist(new Review(version: $otherVersion, verdict: Verdict::Approved, reviewer: $owner));

        // A document the deleted user owns inside someone ELSE's project — the
        // schema permits document.owner != project.owner even though no
        // production code path creates it today.
        $foreignDocument = new Document(owner: $owner, project: $otherProject, title: 'owner doc in other project');
        $em->persist($foreignDocument);
        $foreignVersion = $foreignDocument->addVersion('# Foreign', '<h1>Foreign</h1>');
        $foreignDocumentComment = new Comment(version: $foreignVersion, author: $other, body: 'other user comments on it', anchor: Anchor::unanchored());
        $em->persist($foreignDocumentComment);
        // decision_selections.document_id is NOT DEFERRABLE with no ON DELETE
        // CASCADE, so an answered decision on this document aborts the whole
        // account deletion unless DocumentOwnershipAccountPurger clears it.
        $em->persist(new DecisionSelection($foreignDocument, 'deploy-target', 1, 'Ship straight to production', 1));

        // Tagged, because that document is deleted by DocumentOwnershipAccountPurger
        // rather than by ProjectDeleter, and the join table's FK has no cascade —
        // an untagged fixture cannot tell whether that purger clears it.
        $foreignTag = new Tag($otherProject, 'design');
        $em->persist($foreignTag);
        $foreignDocument->tags->add($foreignTag);

        // A third FK onto document_versions, and NOT DEFERRABLE like the others:
        // a purger that forgets it fails the version delete outright, which is
        // the whole reason this owner-mismatch fixture exists.
        $em->persist(new Highlight(version: $foreignVersion, anchor: Anchor::unanchored()));

        // A surviving document pointing AT the doomed one: only the incoming
        // half of the join-table cleanup can clear this, and without it the
        // delete fails on the foreign key.
        $otherDocument->references->add($foreignDocument);

        // A loose API token not bound to any project's widget/mcp slots.
        [$looseToken] = ApiToken::issue($owner, 'loose-token', ApiTokenScope::Mcp);
        $em->persist($looseToken);

        $connectedAccount = new ConnectedAccount($owner, SocialProvider::Github, 'gh-'.uniqid(), 'owner@github.example');
        $em->persist($connectedAccount);

        $export = new DataExport($owner);
        $em->persist($export);

        $profile = BillingGrants::profileWithTrial($owner, new \DateTimeImmutable('+14 days'));
        $profile->stripeCustomerId = 'cus_delete_me';
        $em->persist($profile);
        foreach ($profile->subscriptions as $subscription) {
            $em->persist($subscription);
        }
        $em->persist(BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'), 'sub_delete_me'));

        $em->flush();

        $exportId = $export->id ?? throw new \LogicException('flushed export always has an id');
        $storage = self::getContainer()->get('test.export.storage');
        \assert($storage instanceof FilesystemOperator);
        $storage->write(DataExport::computeArchiveKey($exportId), 'fixture archive contents');

        return [
            'owner' => $owner,
            'otherId' => $other->id ?? throw new \LogicException('flushed user always has an id'),
            'thirdId' => $third->id ?? throw new \LogicException('flushed user always has an id'),
            'exportId' => $exportId,
            'doomedProject1Id' => $doomed1->id ?? throw new \LogicException('flushed project always has an id'),
            'doomedProject2Id' => $doomed2->id ?? throw new \LogicException('flushed project always has an id'),
            'otherProjectId' => $otherProject->id ?? throw new \LogicException('flushed project always has an id'),
            'replyToOwnerCommentId' => $replyToOwner->id ?? throw new \LogicException('flushed comment always has an id'),
            'grandchildReplyId' => $grandchildReply->id ?? throw new \LogicException('flushed comment always has an id'),
            'foreignDocumentId' => $foreignDocument->id ?? throw new \LogicException('flushed document always has an id'),
            'foreignDocumentCommentId' => $foreignDocumentComment->id ?? throw new \LogicException('flushed comment always has an id'),
            'otherAuthoredCommentId' => $otherAuthoredComment->id ?? throw new \LogicException('flushed comment always has an id'),
        ];
    }

    private function makeUser(EntityManagerInterface $em, string $slug): User
    {
        $user = new User(fullName: 'Deleter Test', email: $slug.'@example.test', password: 'irrelevant-hash');
        $em->persist($user);

        return $user;
    }

    private function seedOwnedProject(EntityManagerInterface $em, User $owner, string $slug): Project
    {
        $project = new Project(owner: $owner, name: $slug);
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $slug.' doc');
        $em->persist($document);
        $version = $document->addVersion('# Hi', '<h1>Hi</h1>');
        $parent = new Comment(version: $version, author: $owner, body: 'root', anchor: Anchor::unanchored());
        $em->persist($parent);
        $reply = new Comment(version: $version, author: $owner, body: 'reply', anchor: Anchor::unanchored(), parent: $parent);
        $em->persist($reply);
        $em->persist(new Review(version: $version, verdict: Verdict::Approved, reviewer: $owner));

        $em->persist(new SiteReviewComment(project: $project, position: 0, body: 'widget comment', selector: 'body', text: 'x', url: 'https://example.test/'));

        [$widgetToken] = ApiToken::issue($owner, $slug.'-widget', ApiTokenScope::SiteReview);
        [$mcpToken] = ApiToken::issue($owner, $slug.'-mcp', ApiTokenScope::Mcp);
        $project->widgetToken = $widgetToken;
        $project->mcpToken = $mcpToken;
        $em->persist($widgetToken);
        $em->persist($mcpToken);

        return $project;
    }
}
