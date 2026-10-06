<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Bridge\Service\WorkerRunToolCallExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunToolCallExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_tool_calls_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'tool-calls-export-mine@example.com');
        $runKey = Uuid::v4();
        $run = $this->seedRun($em, $this->project($em, $exporting, 'Tool Call Export'), runKey: $runKey);
        $this->seedToolCall($run, 2, 'Read');
        $this->seedToolCall($run, 1, 'Bash');
        $this->seedToolCall($this->seedRun($em, $this->project($em, $this->user($em, 'tool-calls-export-other@example.com'), 'Other Tool Calls')));
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertCount(2, $rows);
        self::assertSame([
            'project' => 'Tool Call Export',
            'runKey' => $runKey->toRfc4122(),
            'seq' => 1,
            'tool' => 'Bash',
            'startedAt' => '2026-01-01T10:00:01+00:00',
            'durationMs' => 1500,
            'isError' => false,
            'inSubagent' => false,
            'backgroundId' => null,
            'waitsOn' => null,
            'signatures' => ['Bash'],
            'fullText' => null,
        ], $rows[0]);
        self::assertSame(['seq' => 2, 'tool' => 'Read'], ['seq' => $rows[1]['seq'], 'tool' => $rows[1]['tool']]);
    }

    public function test_the_archive_entry_is_named_after_the_tool_calls(): void
    {
        self::bootKernel();

        self::assertSame('worker_run_tool_calls.json', $this->exporter()->filename());
    }

    private function exporter(): WorkerRunToolCallExporter
    {
        $toolCalls = self::getContainer()->get(WorkerRunToolCallRepository::class);
        self::assertInstanceOf(WorkerRunToolCallRepository::class, $toolCalls);

        return new WorkerRunToolCallExporter($toolCalls);
    }
}
