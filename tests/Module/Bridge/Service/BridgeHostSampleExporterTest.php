<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use App\Module\Bridge\Service\BridgeHostSampleExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeHostSampleExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_host_samples_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'samples-export-mine@example.com');
        $shared = Uuid::v4();
        $bridge = $this->seedBridge($em, $exporting, $shared);
        $this->seedHostSample($bridge, '2026-10-07 12:01:00');
        $this->seedHostSample($bridge, '2026-10-07 12:00:00', cpuPct: [5.5, 100.0], memUsed: 8_000_000_000, swapUsed: 7, batteryPct: 42.5, onAc: false);
        $this->seedHostSample($this->seedBridge($em, $this->user($em, 'samples-export-other@example.com'), $shared), '2026-10-07 12:00:00');
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertCount(2, $rows);
        self::assertSame([
            'bridgeId' => $shared->toRfc4122(),
            'sampledAt' => '2026-10-07T12:00:00+00:00',
            'cpuPct' => [5.5, 100.0],
            'memUsed' => 8_000_000_000,
            'memTotal' => 4000,
            'swapUsed' => 7,
            'batteryPct' => 42.5,
            'onAc' => false,
        ], $rows[0]);
        self::assertSame('2026-10-07T12:01:00+00:00', $rows[1]['sampledAt']);
    }

    public function test_the_archive_entry_is_named_after_the_host_samples(): void
    {
        self::bootKernel();

        self::assertSame('bridge_host_samples.json', $this->exporter()->filename());
    }

    private function exporter(): BridgeHostSampleExporter
    {
        $samples = self::getContainer()->get(BridgeHostSampleRepository::class);
        self::assertInstanceOf(BridgeHostSampleRepository::class, $samples);

        return new BridgeHostSampleExporter($samples);
    }
}
