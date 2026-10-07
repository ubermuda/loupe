<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeCommandTest extends TestCase
{
    public function test_settle_moves_a_pending_command_once(): void
    {
        $command = $this->command('person reason');
        $now = new \DateTimeImmutable('2026-09-29 12:05:00');

        self::assertTrue($command->settle(BridgeCommandState::Refused, 'run_not_found', $now));
        self::assertSame(BridgeCommandState::Refused, $command->state);
        self::assertSame('run_not_found', $command->reason);
        self::assertSame($now, $command->settledAt);

        self::assertFalse($command->settle(BridgeCommandState::Done, null, new \DateTimeImmutable('2026-09-29 12:06:00')));
        self::assertSame(BridgeCommandState::Refused, $command->state);
        self::assertSame($now, $command->settledAt);
    }

    public function test_settle_without_a_reason_keeps_the_reason_of_the_person(): void
    {
        $command = $this->command('person reason');

        $command->settle(BridgeCommandState::Done, null, new \DateTimeImmutable());

        self::assertSame('person reason', $command->reason);
    }

    public function test_settle_caps_the_reason(): void
    {
        $command = $this->command(null);

        $command->settle(BridgeCommandState::Refused, str_repeat('é', BridgeCommand::MAX_REASON_LENGTH + 5), new \DateTimeImmutable());

        self::assertSame(BridgeCommand::MAX_REASON_LENGTH, mb_strlen((string) $command->reason));
    }

    public function test_settle_refuses_pending_as_a_final_state(): void
    {
        $this->expectException(\LogicException::class);

        $this->command(null)->settle(BridgeCommandState::Pending, null, new \DateTimeImmutable());
    }

    public function test_a_bridge_takes_commands_only_when_it_reports_the_capability(): void
    {
        $bridge = new Bridge(new User('Riley Chen', 'riley@example.com', 'x'), Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        self::assertFalse($bridge->takesCommands());

        $bridge->capabilities = ['hooks'];
        self::assertFalse($bridge->takesCommands());

        $bridge->capabilities = ['hooks', Bridge::CAPABILITY_COMMANDS];
        self::assertTrue($bridge->takesCommands());
    }

    public function test_a_bridge_takes_reruns_only_when_it_reports_commands_and_reruns(): void
    {
        $bridge = new Bridge(new User('Riley Chen', 'riley@example.com', 'x'), Uuid::v4(), [], '1.6.0', new \DateTimeImmutable());
        self::assertFalse($bridge->takesReruns());

        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        self::assertFalse($bridge->takesReruns());

        $bridge->capabilities = [Bridge::CAPABILITY_RERUN_COMMAND];
        self::assertFalse($bridge->takesReruns());

        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_RERUN_COMMAND];
        $bridge->cliVersion = 'b4e39aa7';
        self::assertTrue($bridge->takesReruns());
    }

    private function command(?string $reason): BridgeCommand
    {
        $owner = new User('Riley Chen', 'riley@example.com', 'x');
        $project = $this->createStub(Project::class);
        $run = new WorkerRun($project, Uuid::v4(), WorkSubject::CARD, Uuid::v7(), 1, 'plan', WorkerRunState::Running);

        return new BridgeCommand(
            owner: $owner,
            bridgeId: Uuid::v4(),
            project: $project,
            workerRun: $run,
            kind: BridgeCommandKind::StopRun,
            requestedBy: $owner,
            requestedAt: new \DateTimeImmutable('2026-09-29 12:00:00'),
            expiresAt: new \DateTimeImmutable('2026-09-29 12:15:00'),
            reason: $reason,
        );
    }
}
