<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditLogAccountPurger;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The deletion promise for the trail: every record carrying the departed name
 * goes, and the record of what was done to the account stays whole.
 */
final class AuditLogAccountPurgerTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
    }

    public function test_it_removes_what_the_departed_account_did(): void
    {
        $departing = $this->user('departing', 'Departing Person');
        $admin = $this->user('admin', 'Admin Ada');

        $this->record('audit.by_the_departing', $departing, 'Departing Person');
        $this->record('audit.by_the_admin', $admin, 'Admin Ada');

        self::assertSame(['audit.by_the_admin', 'audit.by_the_departing'], $this->operations());

        $this->purge($departing);

        self::assertSame(['audit.by_the_admin'], $this->operations());
    }

    /**
     * A record about the account is the account's own evidence that something
     * was done to it, and the name on it belongs to whoever did it.
     */
    public function test_it_leaves_a_record_about_the_departed_account_completely_intact(): void
    {
        $departing = $this->user('subject', 'Departing Person');
        $admin = $this->user('actor', 'Admin Ada');

        $this->record('audit.about_the_departing', $admin, 'Admin Ada', subjectId: $departing->id);

        self::assertSame(['audit.about_the_departing'], $this->operations());

        $this->purge($departing);

        self::assertSame(['audit.about_the_departing'], $this->operations());

        $kept = $this->row('audit.about_the_departing');
        self::assertSame('Admin Ada', $kept['actor_label']);
        self::assertSame((string) $admin->id, (string) $kept['actor_id']);
        self::assertSame('user', $kept['subject_type']);
        self::assertSame((string) $departing->id, (string) $kept['subject_id']);
    }

    /**
     * The label and the id are written independently, so a record with no id
     * still names the account, and only the label can find it.
     */
    public function test_it_removes_an_unattributed_record_carrying_the_departed_name(): void
    {
        $departing = $this->user('unattributed', 'Departing Person');

        $this->record('audit.unattributed_departing', null, 'Departing Person');
        $this->record('audit.unattributed_other', null, 'Someone Else');

        self::assertSame(['audit.unattributed_departing', 'audit.unattributed_other'], $this->operations());

        $this->purge($departing);

        self::assertSame(['audit.unattributed_other'], $this->operations());
    }

    /**
     * ProjectAccountPurger runs first and calls EntityManager::clear(), so slot
     * 35 always receives a detached user, and both keys are read off it.
     */
    public function test_it_purges_a_detached_user(): void
    {
        $departing = $this->user('detached', 'Departing Person');

        $this->record('audit.by_the_departing', $departing, 'Departing Person');
        $this->record('audit.unattributed_departing', null, 'Departing Person');

        self::assertSame(['audit.by_the_departing', 'audit.unattributed_departing'], $this->operations());

        $this->em->clear();
        self::assertFalse($this->em->getUnitOfWork()->isInIdentityMap($departing));

        $this->purge($departing);

        self::assertSame([], $this->operations());
    }

    private function purge(User $user): void
    {
        new AuditLogAccountPurger($this->em)->purge($user, new AccountDeletionCleanup());
    }

    private function user(string $handle, string $fullName): User
    {
        $user = new User(fullName: $fullName, email: $handle.'@example.com', password: 'x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function record(string $operation, ?User $actor, string $actorLabel, ?Uuid $subjectId = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO audit_log (id, operation, outcome, category, channel, occurred_at, context, actor_id, actor_label, subject_type, subject_id)'
                .' VALUES (:id, :operation, :outcome, :category, :channel, :occurredAt, :context, :actorId, :actorLabel, :subjectType, :subjectId)',
            [
                'id' => (string) Uuid::v7(),
                'operation' => $operation,
                'outcome' => 'success',
                'category' => 'domain',
                'channel' => 'session',
                'occurredAt' => new \DateTimeImmutable()->format('Y-m-d H:i:s.u'),
                'context' => '{}',
                'actorId' => null === $actor ? null : (string) $actor->id,
                'actorLabel' => $actorLabel,
                'subjectType' => null === $subjectId ? null : 'user',
                'subjectId' => null === $subjectId ? null : (string) $subjectId,
            ],
        );
    }

    /** @return list<string> */
    private function operations(): array
    {
        return array_map(
            strval(...),
            $this->connection->fetchFirstColumn('SELECT operation FROM audit_log ORDER BY operation'),
        );
    }

    /** @return array<string, mixed> */
    private function row(string $operation): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM audit_log WHERE operation = :operation', ['operation' => $operation]);
        self::assertIsArray($row);

        return $row;
    }
}
