<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MetricRowSourceTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_bucket_times_count_the_closed_runs_in_range_and_hold_the_runs_with_data(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-buckets-'.uniqid().'@example.com'), 'Row source buckets');
        $other = $this->project($em, $this->user($em, 'row-source-other-'.uniqid().'@example.com'), 'Row source other');
        $withData = $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-09-03 10:00:00'));
        $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-09-02 10:00:00'));
        $analysis = $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-09-02 11:00:00'), subjectType: 'analysis');
        $old = $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-07-01 10:00:00'));
        $open = $this->seedRun($em, $project, state: WorkerRunState::Running, endedAt: new \DateTimeImmutable('2026-09-03 10:00:00'));
        $elsewhere = $this->seedRun($em, $other, endedAt: new \DateTimeImmutable('2026-09-03 10:00:00'));
        $bucketTimes = $this->bucketTimeRepository();
        $bucketTimes->replaceForRun($withData->id ?? throw new \LogicException(), ['tests' => 40, 'git' => 2]);
        $bucketTimes->replaceForRun($analysis->id ?? throw new \LogicException(), ['git' => 5]);
        $bucketTimes->replaceForRun($old->id ?? throw new \LogicException(), ['git' => 7]);
        $bucketTimes->replaceForRun($open->id ?? throw new \LogicException(), ['git' => 8]);
        $bucketTimes->replaceForRun($elsewhere->id ?? throw new \LogicException(), ['git' => 9]);

        $times = $this->source()->bucketTimes($project, new \DateTimeImmutable('2026-09-01 00:00:00'));

        self::assertSame(3, $times->runs);
        $read = $times->times;
        ksort($read);
        foreach ($read as &$buckets) {
            ksort($buckets);
        }
        unset($buckets);
        $expected = [(string) $withData->id => ['git' => 2, 'tests' => 40], (string) $analysis->id => ['git' => 5]];
        ksort($expected);
        self::assertSame($expected, $read);

        $all = $this->source()->bucketTimes($project, null);
        self::assertSame(4, $all->runs);
        self::assertArrayHasKey((string) $old->id, $all->times);
    }

    public function test_bucket_times_of_a_project_with_no_runs_are_empty(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-empty-'.uniqid().'@example.com'), 'Row source empty');

        $times = $this->source()->bucketTimes($project, null);

        self::assertSame(0, $times->runs);
        self::assertSame([], $times->times);
    }

    private function source(): MetricRowSource
    {
        $facts = self::getContainer()->get(WorkerRunFactRepository::class);
        self::assertInstanceOf(WorkerRunFactRepository::class, $facts);

        return new MetricRowSource($facts, $this->createStub(FinishedCardSourceInterface::class), $this->createStub(CardReportSourceInterface::class), $this->bucketTimeRepository());
    }

    private function bucketTimeRepository(): WorkerRunBucketTimeRepository
    {
        $repository = self::getContainer()->get(WorkerRunBucketTimeRepository::class);
        self::assertInstanceOf(WorkerRunBucketTimeRepository::class, $repository);

        return $repository;
    }
}
