<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ResumeAskingRunCommand;
use App\Module\Bridge\Command\ResumeAskingRunHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ResumeAskingRunHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_newest_run_of_the_session_on_the_bridge_gets_a_resume_from_loupe(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('ask-resume');
        $first = $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('2026-10-01 10:00:00'), bridgeId: $bridge->id, state: WorkerRunState::Blocked);
        $session = $first->sessionId ?? throw new \LogicException('A worker run has a session.');
        $newest = $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('2026-10-01 11:00:00'), bridgeId: $bridge->id, state: WorkerRunState::Blocked);
        $newest->sessionId = $session;
        $this->em()->flush();

        $this->resume($project, $bridge->id, $session);

        $commands = $this->commands();
        self::assertCount(1, $commands);
        self::assertSame((string) $newest->id, (string) $commands[0]->workerRun->id);
        self::assertSame(BridgeCommandKind::ResumeRun, $commands[0]->kind);
        self::assertNull($commands[0]->requestedBy);
        self::assertSame(BridgeCommandCause::AskClosed, $commands[0]->cause);
        self::assertSame('ask-closed', BridgeCommandPayload::of($commands[0])['cause']);
    }

    public function test_a_run_that_still_runs_gets_no_resume(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('ask-resume-running');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Running);

        $this->resume($project, $bridge->id, $run->sessionId ?? throw new \LogicException('A worker run has a session.'));

        self::assertSame([], $this->commands());
    }

    public function test_a_session_of_another_bridge_gets_no_resume(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('ask-resume-other-bridge');
        $run = $this->seedRun($this->em(), $project, bridgeId: Uuid::v4(), state: WorkerRunState::Blocked);

        $this->resume($project, $bridge->id, $run->sessionId ?? throw new \LogicException('A worker run has a session.'));

        self::assertSame([], $this->commands());
    }

    public function test_a_bridge_that_takes_no_commands_gets_no_resume(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('ask-resume-old-bridge', capabilities: []);
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Blocked);

        $this->resume($project, $bridge->id, $run->sessionId ?? throw new \LogicException('A worker run has a session.'));

        self::assertSame([], $this->commands());
    }

    public function test_a_resume_requested_since_the_close_gets_no_second_one(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('ask-resume-since');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Blocked);
        $this->seedCommand($this->em(), $run, BridgeCommandState::Done, new \DateTimeImmutable('2026-10-01 12:00:00'), kind: BridgeCommandKind::ResumeRun);

        $this->resume($project, $bridge->id, $run->sessionId ?? throw new \LogicException('A worker run has a session.'), new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertCount(1, $this->commands());
    }

    public function test_a_resume_requested_before_the_close_does_not_stop_a_new_one(): void
    {
        self::bootKernel();
        [$project, $bridge] = $this->scenario('ask-resume-before');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Blocked);
        $this->seedCommand($this->em(), $run, BridgeCommandState::Done, new \DateTimeImmutable('2026-10-01 11:59:59'), kind: BridgeCommandKind::ResumeRun);

        $this->resume($project, $bridge->id, $run->sessionId ?? throw new \LogicException('A worker run has a session.'), new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertCount(2, $this->commands());
    }

    /**
     * @param list<string> $capabilities
     *
     * @return array{Project, Bridge}
     */
    private function scenario(string $name, array $capabilities = [Bridge::CAPABILITY_COMMANDS]): array
    {
        $owner = $this->user($this->em(), $name.'@example.com');
        $project = $this->project($this->em(), $owner, 'Project '.substr(md5($name), 0, 8));
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = $capabilities;
        $this->em()->flush();

        return [$project, $bridge];
    }

    private function resume(Project $project, Uuid $bridgeId, Uuid $sessionId, \DateTimeImmutable $askClosedAt = new \DateTimeImmutable('2026-10-01 12:00:00')): void
    {
        $handler = self::getContainer()->get(ResumeAskingRunHandler::class);
        self::assertInstanceOf(ResumeAskingRunHandler::class, $handler);
        $handler(new ResumeAskingRunCommand($project, $bridgeId, $sessionId, $askClosedAt));
    }

    /** @return list<BridgeCommand> */
    private function commands(): array
    {
        $this->em()->clear();

        $repository = self::getContainer()->get(BridgeCommandRepository::class);
        self::assertInstanceOf(BridgeCommandRepository::class, $repository);

        return array_values($repository->findAll());
    }
}
