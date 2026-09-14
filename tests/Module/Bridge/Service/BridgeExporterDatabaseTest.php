<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\BridgeExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeExporterDatabaseTest extends KernelTestCase
{
    use BridgeScenario;

    /** Both accounts hold a row under one bridge id, so the query must key on the owner and not on the id. */
    public function test_it_exports_the_bridges_of_the_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'bridges-export-mine@example.com');
        $other = $this->user($em, 'bridges-export-other@example.com');
        $shared = Uuid::v4();
        $own = Uuid::v4();
        $this->seedBridge($em, $exporting, $shared, cliVersion: 'mine-shared');
        $this->seedBridge($em, $exporting, $own, cliVersion: 'mine-own');
        $this->seedBridge($em, $other, $shared, cliVersion: 'other-shared');
        $this->seedBridge($em, $other, Uuid::v4(), cliVersion: 'other-own');
        $em->clear();

        $exporter = self::getContainer()->get(BridgeExporter::class);
        self::assertInstanceOf(BridgeExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($exporting), false);

        $versions = array_map(static fn (array $row): mixed => $row['cliVersion'], $rows);
        sort($versions);
        self::assertSame(['mine-own', 'mine-shared'], $versions);
    }
}
