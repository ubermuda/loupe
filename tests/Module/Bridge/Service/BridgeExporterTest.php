<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeExporter;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
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
        $bridge->updateState = CliUpdateState::RolledBack;
        $bridge->updateVersion = '1.3.0';
        $bridge->installMethod = CliInstallMethod::Homebrew;
        $hook = ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'start', 'lastRunAt' => null, 'outcome' => 'never', 'error' => null];
        $bridge->hooks = [$hook];
        $pool = ['name' => 'default', 'size' => 3, 'inUse' => 1, 'queued' => 0];
        $bridge->workerPools = [$pool];
        $bridge->workerPoolsReportedAt = new \DateTimeImmutable('2026-09-14T15:59:00+00:00');
        $account = ['name' => 'work', 'harness' => 'claude-code', 'state' => 'failing', 'reason' => 'not logged in'];
        $bridge->accounts = [$account];
        $bridge->accountsReportedAt = new \DateTimeImmutable('2026-09-14T15:58:00+00:00');
        $bridge->pauseRequested = true;
        $bridge->pauseRequestedAt = new \DateTimeImmutable('2026-09-14T15:30:00+00:00');
        $bridge->pauseRequestedBy = $owner;
        $bridge->pausedReported = false;
        $bridge->capabilities = ['commands'];
        $bridge->name = 'laptop';
        $bridge->requestedName = 'laptop';
        $bridge->pushLogin = 'acme-agent';

        $rows = iterator_to_array(new BridgeExporter($this->repositoryReturning($bridge))->export($owner));

        self::assertSame([[
            'bridgeId' => (string) $id,
            'projects' => [$projectId],
            'cliVersion' => 'b4e39aa7 (dirty)',
            'lastSeenAt' => '2026-09-14T16:00:00+00:00',
            'updateState' => 'rolled-back',
            'updateVersion' => '1.3.0',
            'installMethod' => 'homebrew',
            'hooks' => [$hook],
            'workerPools' => [$pool],
            'workerPoolsReportedAt' => '2026-09-14T15:59:00+00:00',
            'accounts' => [$account],
            'accountsReportedAt' => '2026-09-14T15:58:00+00:00',
            'pauseRequested' => true,
            'pauseRequestedAt' => '2026-09-14T15:30:00+00:00',
            'pausedReported' => false,
            'capabilities' => ['commands'],
            'name' => 'laptop',
            'requestedName' => 'laptop',
            'pushLogin' => 'acme-agent',
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
