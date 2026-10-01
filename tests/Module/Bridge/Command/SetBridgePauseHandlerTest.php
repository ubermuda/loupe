<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\SetBridgePauseCommand;
use App\Module\Bridge\Command\SetBridgePauseHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class SetBridgePauseHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-29T12:00:00+00:00';

    public function test_a_pause_is_stored_with_its_time_and_person(): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $owner = $this->user($this->em(), 'pause-set@example.com');
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();

        $this->pause($owner, $bridge->id, true);

        $stored = $this->reload($owner, $bridge->id);
        self::assertTrue($stored->pauseRequested);
        self::assertSame(self::NOW, $stored->pauseRequestedAt?->format(\DateTimeInterface::ATOM));
        self::assertSame((string) $owner->id, (string) $stored->pauseRequestedBy?->id);
        $record = $audit->record('bridge.pause_changed');
        self::assertTrue($record->context['paused']);
        self::assertSame((string) $bridge->id, $record->subject?->id);
    }

    public function test_a_resume_clears_the_pause_and_records_the_change(): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $owner = $this->user($this->em(), 'pause-clear@example.com');
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->pauseRequested = true;
        $bridge->pauseRequestedAt = new \DateTimeImmutable('2026-09-28 08:00:00');
        $this->em()->flush();

        $this->pause($owner, $bridge->id, false);

        $stored = $this->reload($owner, $bridge->id);
        self::assertFalse($stored->pauseRequested);
        self::assertSame(self::NOW, $stored->pauseRequestedAt?->format(\DateTimeInterface::ATOM));
        self::assertFalse($audit->record('bridge.pause_changed')->context['paused']);
    }

    public function test_a_request_that_changes_nothing_keeps_the_last_change(): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $owner = $this->user($this->em(), 'pause-same@example.com');
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->pauseRequested = true;
        $bridge->pauseRequestedAt = new \DateTimeImmutable('2026-09-28T08:00:00+00:00');
        $this->em()->flush();

        $this->pause($owner, $bridge->id, true);

        self::assertSame('2026-09-28T08:00:00+00:00', $this->reload($owner, $bridge->id)->pauseRequestedAt?->format(\DateTimeInterface::ATOM));
        self::assertSame([], $audit->records('bridge.pause_changed'));
    }

    public function test_a_bridge_that_takes_no_commands_cannot_pause(): void
    {
        $this->boot();
        $owner = $this->user($this->em(), 'pause-outdated@example.com');
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = null;
        $this->em()->flush();

        try {
            $this->pause($owner, $bridge->id, true);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['bridge' => 'bridge.pause.error.bridge_outdated'], $e->errors);
        }
        self::assertFalse($this->reload($owner, $bridge->id)->pauseRequested);
    }

    public function test_a_bridge_that_takes_no_commands_can_still_unpause(): void
    {
        $this->boot();
        $owner = $this->user($this->em(), 'unpause-outdated@example.com');
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = null;
        $bridge->pauseRequested = true;
        $this->em()->flush();

        $this->pause($owner, $bridge->id, false);

        self::assertFalse($this->reload($owner, $bridge->id)->pauseRequested);
    }

    public function test_a_bridge_the_owner_does_not_hold_is_refused(): void
    {
        $this->boot();
        $owner = $this->user($this->em(), 'pause-unknown@example.com');
        $other = $this->user($this->em(), 'pause-unknown-other@example.com');
        $bridge = $this->seedBridge($this->em(), $other);

        try {
            $this->pause($owner, $bridge->id, true);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['bridge' => 'bridge.pause.error.unknown_bridge'], $e->errors);
        }
        self::assertFalse($this->reload($other, $bridge->id)->pauseRequested);
    }

    private function boot(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    private function pause(User $owner, Uuid $bridgeId, bool $paused): void
    {
        $handler = self::getContainer()->get(SetBridgePauseHandler::class);
        self::assertInstanceOf(SetBridgePauseHandler::class, $handler);

        $handler(new SetBridgePauseCommand($owner, $bridgeId, $paused, $owner));
    }

    private function reload(User $owner, Uuid $bridgeId): Bridge
    {
        $em = $this->em();
        $em->clear();
        $bridge = $em->find(Bridge::class, ['owner' => $owner->id, 'id' => $bridgeId]);
        self::assertInstanceOf(Bridge::class, $bridge);

        return $bridge;
    }
}
