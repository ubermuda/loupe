<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Service\WorkerRunBucketTimeExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunBucketTimeExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_bucket_times_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'bucket-export-mine@example.com');
        $runKey = Uuid::v4();
        $run = $this->seedRun($em, $this->project($em, $exporting, 'Bucket Export'), runKey: $runKey);
        $otherRun = $this->seedRun($em, $this->project($em, $this->user($em, 'bucket-export-other@example.com'), 'Other Bucket Export'));
        $repository = $this->repository();
        $repository->replaceForRun($run->id ?? throw new \LogicException(), ['tests' => 40, 'build' => 2]);
        $repository->replaceForRun($otherRun->id ?? throw new \LogicException(), ['tests' => 9]);
        $em->clear();

        $rows = iterator_to_array(new WorkerRunBucketTimeExporter($repository)->export($exporting), false);

        self::assertSame([
            ['project' => 'Bucket Export', 'runKey' => $runKey->toRfc4122(), 'bucket' => 'build', 'ms' => 2],
            ['project' => 'Bucket Export', 'runKey' => $runKey->toRfc4122(), 'bucket' => 'tests', 'ms' => 40],
        ], $rows);
    }

    public function test_the_archive_entry_is_named_after_the_bucket_times(): void
    {
        self::assertSame('worker_run_bucket_times.json', new WorkerRunBucketTimeExporter($this->repository())->filename());
    }

    private function repository(): WorkerRunBucketTimeRepository
    {
        self::bootKernel();
        $repository = self::getContainer()->get(WorkerRunBucketTimeRepository::class);
        self::assertInstanceOf(WorkerRunBucketTimeRepository::class, $repository);

        return $repository;
    }
}
