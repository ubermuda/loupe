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
 * The actor/subject split against real rows. The unit test stubs the
 * repository, so only this one proves the query reads actor_id.
 */
final class AuditLogExporterQueryTest extends KernelTestCase
{
    public function test_a_row_the_user_is_only_the_subject_of_stays_out(): void
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
        $em->flush();

        $exporter = self::getContainer()->get(AuditLogExporter::class);
        self::assertInstanceOf(AuditLogExporter::class, $exporter);

        $rows = iterator_to_array($exporter->export($user), false);

        self::assertCount(1, $rows);
        self::assertSame('account.profile.updated', $rows[0]['operation']);
    }
}
