<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunBucketTimeRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_gives_a_run_exactly_the_rows_it_is_given_and_a_repeat_changes_nothing(): void
    {
        self::bootKernel();
        $run = $this->seedRun($this->em(), $this->project($this->em(), $this->user($this->em(), 'bucket-repo-'.uniqid().'@example.com'), 'Bucket repo'));
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');

        $this->repository()->replaceForRun($runId, ['a' => 1, 'b' => 2]);
        $this->repository()->replaceForRun($runId, ['b' => 5, '123' => 7]);
        $this->repository()->replaceForRun($runId, ['b' => 5, '123' => 7]);

        $rows = $this->em()->getConnection()->fetchAllKeyValue('SELECT bucket, ms FROM bridge_worker_run_bucket_times WHERE run_id = :run', ['run' => (string) $runId]);
        ksort($rows);
        self::assertSame(['123' => 7, 'b' => 5], array_map(intval(...), $rows));

        $this->repository()->replaceForRun($runId, []);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_bucket_times WHERE run_id = :run', ['run' => (string) $runId]));
    }

    public function test_the_database_refuses_a_second_row_for_one_run_and_bucket(): void
    {
        self::bootKernel();
        $run = $this->seedRun($this->em(), $this->project($this->em(), $this->user($this->em(), 'bucket-repo-unique-'.uniqid().'@example.com'), 'Bucket unique'));
        $row = ['run_id' => (string) $run->id, 'bucket' => 'a', 'ms' => 1];
        $this->em()->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7()] + $row);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em()->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7()] + $row);
    }

    public function test_it_reads_the_time_of_a_bucket_as_zero_for_a_run_with_other_rows_and_nothing_for_a_run_with_none(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'bucket-repo-read-'.uniqid().'@example.com'), 'Bucket read');
        $both = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $onlyOther = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $none = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $this->repository()->replaceForRun($both, ['tests' => 40, 'other' => 2]);
        $this->repository()->replaceForRun($onlyOther, ['other' => 3]);

        $times = $this->repository()->findMillisecondsOfRuns([$both, $onlyOther, $none], 'tests');

        self::assertEquals([(string) $both => 40, (string) $onlyOther => 0], $times);
        self::assertSame([], $this->repository()->findMillisecondsOfRuns([], 'tests'));
    }

    public function test_it_reads_every_bucket_of_the_runs_with_rows_and_nothing_for_a_run_with_none(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'bucket-repo-all-'.uniqid().'@example.com'), 'Bucket all');
        $both = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $one = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $none = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $unasked = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $this->repository()->replaceForRun($both, ['tests' => 40, 'other' => 2]);
        $this->repository()->replaceForRun($one, ['git' => 3]);
        $this->repository()->replaceForRun($unasked, ['git' => 9]);

        $times = $this->repository()->findAllMillisecondsOfRuns([$both, $one, $none]);
        ksort($times);
        foreach ($times as &$buckets) {
            ksort($buckets);
        }
        unset($buckets);

        $expected = [(string) $both => ['other' => 2, 'tests' => 40], (string) $one => ['git' => 3]];
        ksort($expected);
        self::assertSame($expected, $times);
        self::assertSame([], $this->repository()->findAllMillisecondsOfRuns([]));
    }

    public function test_the_rows_go_with_the_run(): void
    {
        self::bootKernel();
        $run = $this->seedRun($this->em(), $this->project($this->em(), $this->user($this->em(), 'bucket-repo-cascade-'.uniqid().'@example.com'), 'Bucket cascade'));
        $runId = $run->id ?? throw new \LogicException();
        $this->repository()->replaceForRun($runId, ['a' => 1]);

        $this->em()->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = :id', ['id' => (string) $runId]);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_bucket_times WHERE run_id = :run', ['run' => (string) $runId]));
    }

    private function repository(): WorkerRunBucketTimeRepository
    {
        $repository = self::getContainer()->get(WorkerRunBucketTimeRepository::class);
        self::assertInstanceOf(WorkerRunBucketTimeRepository::class, $repository);

        return $repository;
    }
}
