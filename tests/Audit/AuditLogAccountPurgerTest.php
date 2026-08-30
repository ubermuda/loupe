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
 * The deletion promise for the trail: what the departed account did goes, and
 * the record of what was done to it keeps everything except the stored name.
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

    public function test_it_removes_what_the_departed_account_did_and_scrubs_the_label_on_what_was_done_to_it(): void
    {
        $departing = $this->user('departing');
        $admin = $this->user('admin');

        $this->record('audit.by_the_departing', $departing, 'Departing Person');
        $this->record('audit.about_the_departing', $admin, 'Admin Ada', subjectId: $departing->id);
        $this->record('audit.about_the_admin', $admin, 'Admin Ada', subjectId: $admin->id);

        self::assertSame(
            ['audit.about_the_admin', 'audit.about_the_departing', 'audit.by_the_departing'],
            $this->operations(),
        );

        $this->purge($departing);

        self::assertSame(['audit.about_the_admin', 'audit.about_the_departing'], $this->operations());

        $kept = $this->row('audit.about_the_departing');
        self::assertNull($kept['actor_label']);
        self::assertSame((string) $admin->id, (string) $kept['actor_id']);
        self::assertSame(AuditLogAccountPurger::USER_SUBJECT_TYPE, $kept['subject_type']);
        self::assertSame((string) $departing->id, (string) $kept['subject_id']);

        self::assertSame('Admin Ada', $this->row('audit.about_the_admin')['actor_label']);
    }

    /**
     * ProjectAccountPurger runs first and calls EntityManager::clear(), so slot
     * 35 always receives a detached user.
     */
    public function test_it_purges_a_detached_user(): void
    {
        $departing = $this->user('detached');
        $this->record('audit.by_the_departing', $departing, 'Departing Person');

        self::assertSame(['audit.by_the_departing'], $this->operations());

        $this->em->clear();
        self::assertFalse($this->em->getUnitOfWork()->isInIdentityMap($departing));

        $this->purge($departing);

        self::assertSame([], $this->operations());
    }

    private function purge(User $user): void
    {
        new AuditLogAccountPurger($this->em)->purge($user, new AccountDeletionCleanup());
    }

    private function user(string $handle): User
    {
        $user = new User(fullName: 'Riley Chen', email: $handle.'@example.com', password: 'x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function record(string $operation, User $actor, string $actorLabel, ?Uuid $subjectId = null): void
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
                'actorId' => (string) $actor->id,
                'actorLabel' => $actorLabel,
                'subjectType' => null === $subjectId ? null : AuditLogAccountPurger::USER_SUBJECT_TYPE,
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
