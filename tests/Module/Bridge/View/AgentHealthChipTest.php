<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\View\AgentHealthChip;
use App\Module\Bridge\View\BridgeStatus;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AgentHealthChipTest extends TestCase
{
    #[TestWith([true, false, null, 'stale', 'bridge.agents.status.stale', 'pending'])]
    #[TestWith([true, true, true, 'stale', 'bridge.agents.status.stale', 'pending'])]
    #[TestWith([true, true, false, 'stale', 'bridge.agents.status.stale', 'pending'])]
    #[TestWith([false, true, true, 'paused', 'bridge.agents.status.paused', 'pending'])]
    #[TestWith([false, true, false, 'pausing', 'bridge.agents.status.pausing', 'pending'])]
    #[TestWith([false, true, null, 'pausing', 'bridge.agents.status.pausing', 'pending'])]
    #[TestWith([false, false, true, 'unpausing', 'bridge.agents.status.unpausing', 'pending'])]
    #[TestWith([false, false, false, 'healthy', 'bridge.agents.status.healthy', 'ok'])]
    #[TestWith([false, false, null, 'healthy', 'bridge.agents.status.healthy', 'ok'])]
    public function test_the_chip_follows_the_heartbeat_and_the_pause(bool $quiet, bool $pauseRequested, ?bool $pausedReported, string $state, string $label, string $tone): void
    {
        $bridge = new Bridge(new User(fullName: 'Riley Chen', email: 'chip@example.com', password: 'x'), Uuid::v4(), [], '1.5.0', new \DateTimeImmutable());
        $bridge->pauseRequested = $pauseRequested;
        $bridge->pausedReported = $pausedReported;
        $now = new \DateTimeImmutable();

        $chip = AgentHealthChip::for($bridge, new BridgeStatus($now, $now, $quiet));

        self::assertSame([$state, $label, $tone], [$chip->state, $chip->label, $chip->tone]);
    }
}
