<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunAccountPurger;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerRunAccountPurgerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_takes_the_runs_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'runs-purge-leaving@example.com');
        $staying = $this->user($em, 'runs-purge-staying@example.com');
        $this->seedRun($em, $this->project($em, $leaving, 'Leaving Runs'));
        $keptRunId = $this->seedRun($em, $this->project($em, $staying, 'Staying Runs'))->id;

        $this->purge($leaving);

        $em->clear();
        /** @var list<WorkerRun> $remaining */
        $remaining = $em->createQuery('SELECT r FROM '.WorkerRun::class.' r')->getResult();
        self::assertCount(1, $remaining);
        self::assertSame((string) $keptRunId, (string) $remaining[0]->id);
    }

    public function test_it_takes_runs_that_resume_one_another(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'runs-purge-linked@example.com');
        $project = $this->project($em, $leaving, 'Linked Runs');
        $first = $this->seedRun($em, $project);
        $this->seedRun($em, $project, cardNumber: 2)->continuesRun = $first;
        $em->flush();

        $this->purge($leaving);

        self::assertSame(
            0,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs'),
        );
    }

    /** ProjectAccountPurger clears the EntityManager, so this slot always gets a detached user. */
    public function test_it_purges_a_detached_user(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'runs-purge-detached@example.com');
        $this->seedRun($em, $this->project($em, $leaving, 'Detached Runs'));
        $em->clear();

        $this->purge($leaving);

        self::assertSame(
            0,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs'),
        );
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

        self::assertContains(WorkerRunAccountPurger::class, $ordered);
        self::assertGreaterThan(
            array_search(ProjectAccountPurger::class, $ordered, true),
            array_search(WorkerRunAccountPurger::class, $ordered, true),
        );
    }

    private function purge(User $user): void
    {
        new WorkerRunAccountPurger($this->em())->purge($user, new AccountDeletionCleanup());
    }
}
