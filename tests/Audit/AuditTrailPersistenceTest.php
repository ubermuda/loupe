<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditContext;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;
use Ubermuda\AuditBundle\Entity\AuditLog;
use Ubermuda\AuditBundle\Repository\AuditLogRepository;

final class AuditTrailPersistenceTest extends KernelTestCase
{
    private Auditor $auditor;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        // The context is a long-lived service that kernel.reset clears between
        // units of work in production. Nothing clears it between tests, so one
        // test's erased actor would otherwise silence the next one's.
        static::getContainer()->get(AuditContext::class)->reset();

        $this->auditor = static::getContainer()->get(Auditor::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
    }

    /**
     * The reason the sink buffers. An audit trail that only records what the
     * database kept cannot record a refused or abandoned write, which is
     * exactly the kind worth recording.
     */
    public function test_a_record_made_inside_a_rolled_back_transaction_still_reaches_the_table(): void
    {
        $this->connection->beginTransaction();
        $this->auditor->record('document.deleted', AuditOutcome::Success, ['documentId' => 'doc-1'], new AuditSubject('document', 'doc-1'));
        $this->connection->rollBack();

        $this->auditor->flush();

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('document.deleted', $rows[0]['operation']);
        self::assertSame('{"documentId": "doc-1"}', $rows[0]['context']);
        self::assertSame('document', $rows[0]['subject_type']);
        self::assertSame('doc-1', $rows[0]['subject_id']);
    }

    public function test_the_signed_in_actor_is_stored_as_both_an_association_and_a_label(): void
    {
        $user = $this->signIn();

        $this->auditor->record('document.created', AuditOutcome::Success);
        $this->auditor->flush();

        $record = static::getContainer()->get(AuditLogRepository::class)->findOneBy(['operation' => 'document.created']);

        self::assertInstanceOf(AuditLog::class, $record);
        self::assertInstanceOf(User::class, $record->actor);
        self::assertSame($user->id, $record->actor->id);
        self::assertSame('Riley Chen', $record->actorLabel);
        self::assertSame('session', $record->channel);
    }

    public function test_an_erased_actor_leaves_no_name_on_the_records_its_own_deletion_writes(): void
    {
        $user = $this->signIn();

        // What the purger sets before it runs. The records the deletion writes
        // are buffered until kernel.terminate, so they land after the purger
        // has already removed every row this account was the actor of.
        static::getContainer()->get(AuditContext::class)->erasedActorId = (string) $user->id;

        $this->auditor->record('account.deleted', AuditOutcome::Success);
        $this->auditor->flush();

        $record = static::getContainer()->get(AuditLogRepository::class)->findOneBy(['operation' => 'account.deleted']);

        self::assertInstanceOf(AuditLog::class, $record);
        self::assertNull($record->actorLabel);
        self::assertNull($record->actor);
    }

    public function test_erasing_one_actor_leaves_another_actors_name_alone(): void
    {
        $user = $this->signIn();

        static::getContainer()->get(AuditContext::class)->erasedActorId = 'some-other-account';

        $this->auditor->record('document.created', AuditOutcome::Success);
        $this->auditor->flush();

        $record = static::getContainer()->get(AuditLogRepository::class)->findOneBy(['operation' => 'document.created']);

        self::assertInstanceOf(AuditLog::class, $record);
        self::assertSame('Riley Chen', $record->actorLabel);
        self::assertInstanceOf(User::class, $record->actor);
        self::assertSame($user->id, $record->actor->id);
    }

    /** A refusal is the row a reader goes looking for, so it must survive the round trip. */
    public function test_a_refusal_reaches_the_column_as_a_refusal(): void
    {
        $this->auditor->record('document.access_denied', AuditOutcome::Refused);
        $this->auditor->record('document.created', AuditOutcome::Success);
        $this->auditor->flush();

        $rows = $this->rows();
        self::assertSame(['refused', 'success'], array_column($rows, 'outcome'));

        $record = static::getContainer()->get(AuditLogRepository::class)->findOneBy(['operation' => 'document.access_denied']);

        self::assertInstanceOf(AuditLog::class, $record);
        self::assertSame(AuditOutcome::Refused, $record->outcome);
    }

    public function test_a_deleted_user_row_nulls_the_actor_foreign_key_and_keeps_the_label(): void
    {
        $user = $this->signIn();

        $this->auditor->record('document.created', AuditOutcome::Success);
        $this->auditor->flush();

        static::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [(string) $user->id]);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['actor_id']);
        self::assertSame('Riley Chen', $rows[0]['actor_label']);
    }

    public function test_the_container_reset_discards_a_buffer_the_next_unit_of_work_must_not_inherit(): void
    {
        $this->auditor->record('document.created', AuditOutcome::Success);
        $this->auditor->flush();

        self::assertCount(1, $this->rows());

        $this->auditor->record('document.deleted', AuditOutcome::Success);
        static::getContainer()->get('services_resetter')->reset();
        $this->auditor->flush();

        self::assertSame(['document.created'], array_column($this->rows(), 'operation'));
    }

    /**
     * Self-service deletion is the one flow whose actor is gone before the
     * record of it drains. The row must land anyway, keeping the name the
     * deleted account went by.
     */
    public function test_a_record_whose_actor_was_deleted_reaches_the_table_and_the_purgers_own_record_carries_no_name(): void
    {
        $user = $this->signIn();
        $userId = (string) $user->id;

        $this->auditor->record(
            'account.deleted',
            AuditOutcome::Success,
            ['userId' => $userId],
            new AuditSubject('user', $userId),
        );

        static::getContainer()->get(AccountPurger::class)->purge($user);
        $this->auditor->flush();

        $rows = $this->rows();
        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame('account.deleted', $row['operation']);
            self::assertNull($row['actor_id'], 'the reference to the deleted account must be nulled');
            self::assertSame($userId, $row['subject_id']);
        }

        // The two rows differ, and the difference is the point. The first was
        // recorded before the deletion began, so it keeps the name the account
        // acted under. The purger's own record carries none: it is written
        // after the purger has removed every row this account was the actor of,
        // so a name on it would be the one thing the deletion could not erase.
        $labels = array_column($rows, 'actor_label');
        sort($labels);
        self::assertSame([null, 'Riley Chen'], [$labels[0], $labels[1]]);
    }

    private function signIn(): User
    {
        $user = new User(fullName: 'Riley Chen', email: 'riley-audit@example.com', password: 'x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM audit_log ORDER BY occurred_at, id');
    }
}
