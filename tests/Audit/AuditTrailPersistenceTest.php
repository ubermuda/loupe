<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditSubject;
use App\Module\Audit\Entity\AuditLog;
use App\Module\Audit\Repository\AuditLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class AuditTrailPersistenceTest extends KernelTestCase
{
    private Auditor $auditor;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

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
        $this->auditor->record('document.deleted', ['documentId' => 'doc-1'], new AuditSubject('document', 'doc-1'));
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

        $this->auditor->record('document.created');
        $this->auditor->flush();

        $record = static::getContainer()->get(AuditLogRepository::class)->findOneBy(['operation' => 'document.created']);

        self::assertInstanceOf(AuditLog::class, $record);
        self::assertInstanceOf(User::class, $record->actor);
        self::assertSame($user->id, $record->actor->id);
        self::assertSame('Riley Chen', $record->actorLabel);
        self::assertSame('session', $record->channel);
    }

    public function test_deleting_the_actor_keeps_the_record_and_its_label(): void
    {
        $user = $this->signIn();

        $this->auditor->record('document.created');
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
        $this->auditor->record('document.created');
        $this->auditor->flush();

        self::assertCount(1, $this->rows());

        $this->auditor->record('document.deleted');
        static::getContainer()->get('services_resetter')->reset();
        $this->auditor->flush();

        self::assertSame(['document.created'], array_column($this->rows(), 'operation'));
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
