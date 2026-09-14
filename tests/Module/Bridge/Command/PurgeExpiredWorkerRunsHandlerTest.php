<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\PurgeExpiredWorkerRunsCommand;
use App\Module\Bridge\Command\PurgeExpiredWorkerRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class PurgeExpiredWorkerRunsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-13 12:00:00';

    public function test_it_deletes_a_run_past_the_window_and_keeps_one_inside_it(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'runs-sweep@example.com'), 'Swept Runs');
        $expired = $this->seedRun($em, $project, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $fresh = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 00:00:00'), cardNumber: 2);

        $purged = ($this->handler())(new PurgeExpiredWorkerRunsCommand());

        self::assertSame(1, $purged);
        $em->clear();
        self::assertNull($em->find(WorkerRun::class, $expired->id));
        self::assertNotNull($em->find(WorkerRun::class, $fresh->id));
    }

    /** The sweep reads the flag, so an operator who shortens the window sees it apply on the next tick. */
    public function test_the_flag_moves_the_cut(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'runs-sweep-flag@example.com'), 'Flagged Sweep');
        $run = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 00:00:00'));

        self::assertSame(0, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));

        $this->setRetentionDays(7);

        self::assertSame(1, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));
        $em->clear();
        self::assertNull($em->find(WorkerRun::class, $run->id));
    }

    /**
     * A window of zero would take every run on the first tick, so the policy
     * floors it at one day. The fixture sits inside that one day and outside a
     * zero-day window, which is what tells the two apart.
     */
    public function test_a_window_of_zero_reads_as_one_day(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'runs-sweep-zero@example.com'), 'Zero Sweep');
        $run = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-13 00:00:00'));
        $this->setRetentionDays(0);

        self::assertSame(0, ($this->handler())(new PurgeExpiredWorkerRunsCommand()));
        $em->clear();
        self::assertNotNull($em->find(WorkerRun::class, $run->id));
    }

    private function setRetentionDays(int $days): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[WorkerRunRetentionPolicy::FLAG]->value = $days;
        $this->em()->flush();

        // The reader caches every flag for the life of the request, and a test
        // is one request, so a write alone would not reach the policy.
        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    private function handler(): PurgeExpiredWorkerRunsHandler
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $flags = self::getContainer()->get(FeatureFlagService::class);
        self::assertInstanceOf(FeatureFlagService::class, $flags);

        return new PurgeExpiredWorkerRunsHandler(
            new WorkerRunRepository($registry),
            new WorkerRunRetentionPolicy($flags, 180),
            new MockClock(new \DateTimeImmutable(self::NOW)),
        );
    }
}
