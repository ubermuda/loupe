<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerRunFactWriterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_writes_the_named_runs_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-writer@example.com'), 'Facts Writer');
        $named = $this->seedRun($em, $project);
        $this->seedRun($em, $project, cardNumber: 2);
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM bridge_worker_run_facts');

        $this->writer()->upsert([]);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_facts'));

        $this->writer()->upsert([$named->id ?? throw new \LogicException('The run has no id.')]);

        self::assertSame(
            [(string) $named->id],
            $connection->fetchFirstColumn('SELECT run_id FROM bridge_worker_run_facts'),
        );
    }

    private function writer(): WorkerRunFactWriter
    {
        $writer = self::getContainer()->get(WorkerRunFactWriter::class);
        self::assertInstanceOf(WorkerRunFactWriter::class, $writer);

        return $writer;
    }
}
