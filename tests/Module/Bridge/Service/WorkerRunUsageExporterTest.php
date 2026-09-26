<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\Service\WorkerRunUsageExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunUsageExporterTest extends KernelTestCase
{
    use BridgeScenario;

    /** A row whose run the retention sweep took is still the account's data. */
    public function test_it_exports_the_usage_of_the_account_alone_with_its_unlinked_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'usage-export-mine@example.com');
        $project = $this->project($em, $exporting, 'Usage Export');
        $runKey = Uuid::v4();
        $linked = $this->seedRun($em, $project, ruleName: 'review', runKey: $runKey);
        $this->seedUsage($em, $linked);
        $swept = $this->seedRun($em, $project, cardNumber: 2);
        $this->seedUsage($em, $swept, 'claude-haiku');
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [(string) $swept->id]);
        $this->seedUsage($em, $this->seedRun($em, $this->project($em, $this->user($em, 'usage-export-other@example.com'), 'Other Usage')));
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        usort($rows, static fn (array $a, array $b): int => $a['model'] <=> $b['model']);
        self::assertCount(2, $rows);
        self::assertSame([
            'project' => 'Usage Export',
            'cardId' => (string) $swept->cardId,
            'ruleName' => 'plan',
            'runKey' => null,
            'model' => 'claude-haiku',
            'source' => 'reported',
            'inputTokens' => 100,
            'outputTokens' => 20,
            'cacheReadTokens' => 300,
            'cacheWriteTokens' => 40,
            'costUsd' => '0.012345',
        ], $rows[0]);
        self::assertSame('claude-opus-5-5', $rows[1]['model']);
        self::assertSame('review', $rows[1]['ruleName']);
        self::assertSame($runKey->toRfc4122(), $rows[1]['runKey']);
    }

    public function test_the_archive_entry_is_named_after_the_usage(): void
    {
        self::bootKernel();

        self::assertSame('worker_run_usage.json', $this->exporter()->filename());
    }

    private function exporter(): WorkerRunUsageExporter
    {
        $usage = self::getContainer()->get(WorkerRunUsageRepository::class);
        self::assertInstanceOf(WorkerRunUsageRepository::class, $usage);

        return new WorkerRunUsageExporter($usage);
    }
}
