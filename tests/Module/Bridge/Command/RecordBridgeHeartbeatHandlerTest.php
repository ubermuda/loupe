<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\ValueObject\CliUpdateState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class RecordBridgeHeartbeatHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_first_heartbeat_of_a_bridge_is_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-audit-first@example.com');
        $project = $this->project($em, $owner, 'Audited Heartbeat');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [(string) $project->id], 'b4e39aa7'));

        $record = $audit->record('bridge.bridge_registered');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(['bridgeId' => (string) $bridgeId, 'projects' => 1], $record->context);
    }

    /** A replace arrives once a minute per bridge, so it must leave the trail alone. */
    public function test_a_heartbeat_that_replaces_the_row_is_not_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-audit-replace@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));
        self::assertCount(1, $audit->records('bridge.bridge_registered'));

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertCount(1, $audit->records('bridge.bridge_registered'));
        self::assertSame(['bridge.bridge_registered'], $audit->operations());
    }

    public function test_it_stores_the_update_report_and_answers_the_cli_range(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-update-report@example.com');

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, Uuid::v4(), [], '1.2.0', CliUpdateState::Blocked, '1.3.0'));

        self::assertSame(CliCompatibility::RANGE, $result->cliRange);
        self::assertSame(CliUpdateState::Blocked, $result->bridge->updateState);
        self::assertSame('1.3.0', $result->bridge->updateVersion);
    }

    private function handler(): RecordBridgeHeartbeatHandler
    {
        $handler = self::getContainer()->get(RecordBridgeHeartbeatHandler::class);
        self::assertInstanceOf(RecordBridgeHeartbeatHandler::class, $handler);

        return $handler;
    }
}
