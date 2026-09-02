<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditLogExporter;
use App\Module\Account\Entity\User;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The actor/subject reach against real rows. The unit test stubs the
 * repository, so only this one proves the query reads both columns.
 */
final class AuditLogExporterQueryTest extends KernelTestCase
{
    public function test_it_exports_what_the_user_did_and_what_was_done_to_them(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User('Alice A', 'alice-'.uniqid().'@example.com', 'x');
        $admin = new User('Admin B', 'admin-'.uniqid().'@example.com', 'x');
        $other = new User('Other C', 'other-'.uniqid().'@example.com', 'x');
        $em->persist($user);
        $em->persist($admin);
        $em->persist($other);
        $em->flush();

        // Alice is both the actor and the subject here, which is the row a
        // second query would export twice.
        $em->persist(new AuditLog(
            operation: 'account.profile.updated',
            outcome: AuditOutcome::Success,
            category: 'domain',
            channel: 'session',
            actor: $user,
            actorLabel: 'Alice A',
            subjectType: 'user',
            subjectId: (string) $user->id,
        ));
        $em->persist(new AuditLog(
            operation: 'account.admin.user_suspended',
            outcome: AuditOutcome::Success,
            category: 'domain',
            channel: 'session',
            actor: $admin,
            actorLabel: 'Admin B',
            subjectType: 'user',
            subjectId: (string) $user->id,
        ));
        $em->persist(new AuditLog(
            operation: 'account.admin.user_promoted',
            outcome: AuditOutcome::Success,
            category: 'domain',
            channel: 'session',
            actor: $admin,
            actorLabel: 'Admin B',
            subjectType: 'user',
            subjectId: (string) $other->id,
        ));
        $em->flush();

        $exporter = self::getContainer()->get(AuditLogExporter::class);
        self::assertInstanceOf(AuditLogExporter::class, $exporter);

        $rows = iterator_to_array($exporter->export($user), false);

        $operations = array_map(static fn (array $row): string => (string) $row['operation'], $rows);
        sort($operations);

        // Two, not three: Alice is the actor and the subject of the first row,
        // and one query with an OR returns it once.
        self::assertCount(2, $rows);
        self::assertSame(['account.admin.user_suspended', 'account.profile.updated'], $operations);
    }

    public function test_a_record_about_somebody_else_stays_out_of_the_export(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User('Alice A', 'alice-'.uniqid().'@example.com', 'x');
        $other = new User('Other C', 'other-'.uniqid().'@example.com', 'x');
        $em->persist($user);
        $em->persist($other);
        $em->flush();

        $em->persist(new AuditLog(
            operation: 'account.admin.user_suspended',
            outcome: AuditOutcome::Success,
            category: 'domain',
            channel: 'session',
            actor: $other,
            actorLabel: 'Other C',
            subjectType: 'user',
            subjectId: (string) $other->id,
        ));
        $em->flush();

        $exporter = self::getContainer()->get(AuditLogExporter::class);
        self::assertInstanceOf(AuditLogExporter::class, $exporter);

        self::assertSame([], iterator_to_array($exporter->export($user), false));
    }

    /** The writer of a subject row is somebody else, and their name never leaves with the export. */
    public function test_the_export_carries_no_name_of_whoever_wrote_a_subject_row(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User('Alice A', 'alice-'.uniqid().'@example.com', 'x');
        $admin = new User('Admin B', 'admin-'.uniqid().'@example.com', 'x');
        $em->persist($user);
        $em->persist($admin);
        $em->flush();

        $em->persist(new AuditLog(
            operation: 'account.admin.user_suspended',
            outcome: AuditOutcome::Success,
            category: 'domain',
            channel: 'session',
            actor: $admin,
            actorLabel: 'Admin B',
            subjectType: 'user',
            subjectId: (string) $user->id,
        ));
        $em->flush();

        $exporter = self::getContainer()->get(AuditLogExporter::class);
        self::assertInstanceOf(AuditLogExporter::class, $exporter);

        $rows = iterator_to_array($exporter->export($user), false);

        self::assertCount(1, $rows);
        self::assertArrayNotHasKey('actorLabel', $rows[0]);
        self::assertStringNotContainsString('Admin B', json_encode($rows[0], JSON_THROW_ON_ERROR));
    }
}
