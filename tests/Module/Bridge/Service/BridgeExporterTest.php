<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeExporterTest extends TestCase
{
    public function test_it_exports_every_field_of_a_bridge(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $id = Uuid::v4();
        $projectId = (string) Uuid::v7();
        $bridge = new Bridge($owner, $id, [$projectId], 'b4e39aa7 (dirty)', new \DateTimeImmutable('2026-09-14T16:00:00+00:00'));

        $rows = iterator_to_array(new BridgeExporter($this->repositoryReturning($bridge))->export($owner));

        self::assertSame([[
            'bridgeId' => (string) $id,
            'projects' => [$projectId],
            'cliVersion' => 'b4e39aa7 (dirty)',
            'lastSeenAt' => '2026-09-14T16:00:00+00:00',
        ]], $rows);
    }

    public function test_the_archive_entry_is_named_after_the_bridges(): void
    {
        self::assertSame('bridges.json', new BridgeExporter($this->repositoryReturning())->filename());
    }

    private function repositoryReturning(Bridge ...$bridges): BridgeRepository
    {
        /** @var BridgeRepository&Stub $repository */
        $repository = $this->createStub(BridgeRepository::class);
        $repository->method('findByOwner')->willReturn(array_values($bridges));

        return $repository;
    }
}
