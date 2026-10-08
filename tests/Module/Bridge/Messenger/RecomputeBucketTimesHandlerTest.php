<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Messenger;

use App\Module\Bridge\Command\RecomputeProjectBucketTimesHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Bridge\Messenger\RecomputeBucketTimesHandler;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Bridge\ValueObject\WorkerRunToolCallKind;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class RecomputeBucketTimesHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_recomputes_the_runs_of_the_project_that_hold_tool_calls_and_no_other(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'recompute-'.uniqid().'@example.com'), 'Recompute');
        $other = $this->project($em, $this->user($em, 'recompute-other-'.uniqid().'@example.com'), 'Recompute other');
        $withCalls = $this->seedRun($em, $project);
        $this->seedRun($em, $project);
        $elsewhere = $this->seedRun($em, $other);
        $this->call($withCalls);
        $this->call($elsewhere);

        $this->handler()(new RecomputeBucketTimes((string) $project->id));
        $this->handler()(new RecomputeBucketTimes((string) $project->id));

        $rows = $em->getConnection()->fetchAllAssociative('SELECT run_id, bucket, ms FROM bridge_worker_run_bucket_times WHERE run_id IN (:a, :b, :c)', ['a' => (string) $withCalls->id, 'b' => (string) $elsewhere->id, 'c' => (string) Uuid::v7()]);
        self::assertSame([['run_id' => (string) $withCalls->id, 'bucket' => 'other', 'ms' => 700]], $rows);
    }

    public function test_it_handles_more_runs_than_one_chunk(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'recompute-chunks-'.uniqid().'@example.com'), 'Recompute chunks');
        $runs = [];
        for ($i = 0; $i < RecomputeProjectBucketTimesHandler::CHUNK + 3; ++$i) {
            $runs[] = $run = $this->seedRun($em, $project);
            $this->call($run);
        }

        $this->handler()(new RecomputeBucketTimes((string) $project->id));

        self::assertSame(\count($runs), (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_bucket_times b JOIN bridge_worker_runs r ON r.id = b.run_id WHERE r.project_id = :project', ['project' => (string) $project->id]));
    }

    public function test_a_deleted_project_is_no_error(): void
    {
        self::bootKernel();
        $count = 'SELECT COUNT(*) FROM bridge_worker_run_bucket_times';
        $before = (int) $this->em()->getConnection()->fetchOne($count);

        $this->handler()(new RecomputeBucketTimes((string) Uuid::v7()));

        self::assertSame($before, (int) $this->em()->getConnection()->fetchOne($count));
    }

    public function test_the_message_goes_to_the_async_transport(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $bus->dispatch(new RecomputeBucketTimes((string) Uuid::v7()));

        self::assertCount(1, array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof RecomputeBucketTimes));
    }

    private function call(WorkerRun $run): void
    {
        $repository = self::getContainer()->get(WorkerRunToolCallRepository::class);
        self::assertInstanceOf(WorkerRunToolCallRepository::class, $repository);
        $repository->insertNew($run, [
            new WorkerRunToolCallReport(1, 'Bash', WorkerRunToolCallKind::Shell, new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC')), 500, false, false, null, null, ['Bash'], null),
            new WorkerRunToolCallReport(2, 'Bash', WorkerRunToolCallKind::Shell, new \DateTimeImmutable('2026-01-01 10:00:01', new \DateTimeZone('UTC')), 200, false, false, null, null, ['Bash'], null),
        ]);
    }

    private function handler(): RecomputeBucketTimesHandler
    {
        $handler = self::getContainer()->get(RecomputeBucketTimesHandler::class);
        self::assertInstanceOf(RecomputeBucketTimesHandler::class, $handler);

        return $handler;
    }
}
