<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Service\BridgeAccountPurger;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeAccountPurgerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_takes_the_bridges_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'bridges-purge-leaving@example.com');
        $staying = $this->user($em, 'bridges-purge-staying@example.com');
        // The staying account holds a row under the same bridge id, which must survive.
        $shared = Uuid::v4();
        $this->seedBridge($em, $leaving, $shared);
        $this->seedBridge($em, $leaving);
        $this->seedBridge($em, $staying, $shared);

        $this->purge($leaving);

        self::assertSame(
            [[(string) $staying->id, (string) $shared]],
            $em->getConnection()->fetchAllNumeric('SELECT owner_id, id FROM bridges'),
        );
    }

    /** The purger deletes bridges by SQL, so only the foreign key can take their samples. */
    public function test_it_takes_the_host_samples_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'samples-purge-leaving@example.com');
        $staying = $this->user($em, 'samples-purge-staying@example.com');
        $shared = Uuid::v4();
        $this->seedHostSample($this->seedBridge($em, $leaving, $shared), '2026-10-07 12:00:00');
        $this->seedHostSample($this->seedBridge($em, $staying, $shared), '2026-10-07 12:00:00');

        $this->purge($leaving);

        self::assertSame(
            [[(string) $staying->id, (string) $shared]],
            $em->getConnection()->fetchAllNumeric('SELECT owner_id, bridge_id FROM bridge_host_samples'),
        );
    }

    /** A command whose project went by any other path would otherwise hold the users row. */
    public function test_it_takes_the_commands_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'commands-purge-leaving@example.com');
        $staying = $this->user($em, 'commands-purge-staying@example.com');
        $this->seedCommand($em, $this->seedRun($em, $this->project($em, $leaving, 'Leaving Commands')));
        $kept = $this->seedCommand($em, $this->seedRun($em, $this->project($em, $staying, 'Staying Commands')));

        $this->purge($leaving);

        self::assertSame([(string) $kept->id], $em->getConnection()->fetchFirstColumn('SELECT id FROM bridge_commands'));
    }

    /** The users row goes by SQL, so the foreign keys clear what points at the person. */
    public function test_account_deletion_clears_the_person_on_another_accounts_bridge_and_commands(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'commands-requester-leaving@example.com');
        $staying = $this->user($em, 'commands-requester-staying@example.com');
        $bridge = $this->seedBridge($em, $staying);
        $bridge->pauseRequested = true;
        $bridge->pauseRequestedBy = $leaving;
        $command = $this->seedCommand($em, $this->seedRun($em, $this->project($em, $staying, 'Requested Commands')), requestedBy: $leaving);
        $em->flush();
        $connection = $em->getConnection();
        self::assertSame((string) $leaving->id, $connection->fetchOne('SELECT requested_by_id FROM bridge_commands'));

        $accountPurger = static::getContainer()->get(AccountPurger::class);
        self::assertInstanceOf(AccountPurger::class, $accountPurger);
        $accountPurger->purge($leaving);

        self::assertSame(
            [['id' => (string) $command->id, 'requested_by_id' => null]],
            $connection->fetchAllAssociative('SELECT id, requested_by_id FROM bridge_commands'),
        );
        self::assertSame(
            [['pause_requested' => true, 'pause_requested_by_id' => null]],
            $connection->fetchAllAssociative('SELECT pause_requested, pause_requested_by_id FROM bridges'),
        );
    }

    /** ProjectAccountPurger clears the EntityManager, so this slot always gets a detached user. */
    public function test_it_purges_a_detached_user(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'bridges-purge-detached@example.com');
        $this->seedBridge($em, $leaving);
        $em->clear();

        $this->purge($leaving);

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridges'));
    }

    /** A purger that is not tagged never runs, and no test of its statements would notice. */
    public function test_it_is_registered_after_the_project_purger(): void
    {
        self::bootKernel();
        $accountPurger = static::getContainer()->get(AccountPurger::class);
        $purgers = new \ReflectionProperty(AccountPurger::class, 'purgers')->getValue($accountPurger);
        self::assertIsArray($purgers);

        $ordered = array_values(array_map(
            static fn (object $purger): string => $purger::class,
            array_filter($purgers, is_object(...)),
        ));

        self::assertContains(BridgeAccountPurger::class, $ordered);
        self::assertGreaterThan(
            array_search(ProjectAccountPurger::class, $ordered, true),
            array_search(BridgeAccountPurger::class, $ordered, true),
        );
    }

    private function purge(User $user): void
    {
        new BridgeAccountPurger($this->em())->purge($user, new AccountDeletionCleanup());
    }
}
