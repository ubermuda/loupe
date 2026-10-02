<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Mcp\BridgeCommandCancelTool;
use App\Module\Bridge\Mcp\WorkerRunResumeTool;
use App\Module\Bridge\Mcp\WorkerRunStopTool;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Service\CardColumnLookupInterface;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunWriteToolsTest extends KernelTestCase
{
    use BridgeScenario;
    use McpTokenScenario;

    private Project $project;
    private Project $other;
    private Bridge $bridge;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'mcp-write-'.uniqid().'@example.com');
        $this->project = $this->project($em, $owner, 'Bound');
        $this->other = $this->project($em, $owner, 'Other');
        $this->bridge = $this->seedBridge($em, $owner, projects: [(string) $this->project->id, (string) $this->other->id]);
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $em->flush();
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_a_resume_writes_a_command_that_the_token_user_requested(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished);

        $result = $this->resume()([(string) $run->id]);

        $command = $this->onlyCommand();
        self::assertSame(['results' => [['runId' => (string) $run->id, 'outcome' => 'resumed', 'commandId' => (string) $command->id]]], $result);
        self::assertSame(BridgeCommandKind::ResumeRun, $command->kind);
        self::assertSame(BridgeCommandState::Pending, $command->state);
        self::assertSame((string) $this->owner()->id, (string) $command->requestedBy?->id);
    }

    public function test_a_batch_resumes_each_run_on_its_own_and_keeps_the_order(): void
    {
        $first = $this->runIn(WorkerRunState::Failed, exitCode: 1);
        $foreign = $this->runIn(WorkerRunState::Blocked, project: $this->other);
        $unknown = '0199a1b2-0000-7000-8000-00000000abcd';
        $waiting = $this->runIn(WorkerRunState::GaveUp);
        $this->seedCommand($this->em(), $waiting, kind: BridgeCommandKind::ResumeRun);
        $succeeded = $this->runIn(WorkerRunState::Succeeded);
        $last = $this->runIn(WorkerRunState::TimedOut);

        $results = $this->resume()([(string) $first->id, (string) $foreign->id, $unknown, (string) $waiting->id, (string) $succeeded->id, (string) $last->id])['results'];

        self::assertSame(
            ['resumed', 'refused', 'refused', 'refused', 'refused', 'resumed'],
            array_column($results, 'outcome'),
        );
        self::assertSame([null, 'not-found', 'not-found', 'pending', 'not-resumable', null], array_map(static fn (array $row): ?string => $row['code'] ?? null, $results));
        self::assertSame(\sprintf('Worker run "%s" not found or not accessible.', $foreign->id), $results[1]['message'] ?? null);
        self::assertSame(\sprintf('Worker run "%s" not found or not accessible.', $unknown), $results[2]['message'] ?? null);
        self::assertSame('This run already has a command that waits for its bridge.', $results[3]['message'] ?? null);
        self::assertSame('Only an ended run can resume.', $results[4]['message'] ?? null);
        self::assertSame(3, $this->countCommands($this->em()));
    }

    public function test_the_same_run_twice_resumes_once_and_then_reads_pending(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished);

        $results = $this->resume()([(string) $run->id, (string) $run->id])['results'];

        self::assertSame(['resumed', 'refused'], array_column($results, 'outcome'));
        self::assertSame('pending', $results[1]['code'] ?? null);
        self::assertSame(1, $this->countCommands($this->em()));
    }

    /**
     * An unexpected failure can leave the database in any state, so the rows
     * after it are not attempted. The rows before it keep their result.
     */
    public function test_an_unexpected_failure_stops_the_batch_and_keeps_the_earlier_rows(): void
    {
        // The setup builds the lookup, and a built service cannot be replaced.
        self::ensureKernelShutdown();
        self::bootKernel();
        self::getContainer()->set(CardColumnLookupInterface::class, new class implements CardColumnLookupInterface {
            #[\Override]
            public function columnOf(Project $project, Uuid $cardId): ?string
            {
                throw new \RuntimeException('The board is down.');
            }
        });
        $this->project = $this->em()->find(Project::class, $this->project->id) ?? throw new \LogicException('The setup project exists.');
        $this->actAsMcpTokenBoundTo($this->project);
        $first = $this->runIn(WorkerRunState::Unfinished);
        $failing = $this->runIn(WorkerRunState::Unfinished);
        $failing->cardColumn = 'implementation';
        $this->em()->flush();
        $later = $this->runIn(WorkerRunState::Unfinished);

        $unknown = '0199a1b2-0000-7000-8000-00000000abcd';

        $results = $this->resume()([(string) $first->id, (string) $failing->id, (string) $later->id, $unknown])['results'];

        self::assertSame('resumed', $results[0]['outcome']);
        self::assertSame(
            ['runId' => (string) $failing->id, 'outcome' => 'error', 'message' => 'The outcome is unknown. Read worker_run_list.'],
            $results[1],
        );
        self::assertSame(['runId' => (string) $later->id, 'outcome' => 'not-attempted'], $results[2]);
        self::assertSame(['runId' => $unknown, 'outcome' => 'not-attempted'], $results[3]);
        self::assertSame(1, $this->countCommands($this->em()));
    }

    public function test_a_stop_of_a_run_with_a_waiting_command_is_refused(): void
    {
        $run = $this->runIn(WorkerRunState::Running);
        $this->seedCommand($this->em(), $run);

        $row = $this->stop()((string) $run->id)['results'][0];

        self::assertSame(['refused', 'pending'], [$row['outcome'], $row['code'] ?? null]);
        self::assertSame(1, $this->countCommands($this->em()));
    }

    public function test_a_stop_of_an_interactive_session_is_refused(): void
    {
        $run = $this->seedRun($this->em(), $this->project, bridgeId: $this->bridge->id, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);

        $row = $this->stop()((string) $run->id)['results'][0];

        self::assertSame(['refused', 'not-controllable'], [$row['outcome'], $row['code'] ?? null]);
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_run_with_no_bridge_is_refused(): void
    {
        $run = new WorkerRun($this->project, null, Uuid::v7(), 1, 'plan', WorkerRunState::Unfinished, runKey: Uuid::v7(), sessionId: Uuid::v4());
        $this->em()->persist($run);
        $this->em()->flush();

        self::assertSame(['no-bridge', 'No bridge runs this session, so no bridge can take the command.'], $this->resumeRefusal($run));
    }

    public function test_a_run_whose_bridge_is_gone_is_refused(): void
    {
        $run = $this->seedRun($this->em(), $this->project, state: WorkerRunState::Unfinished);

        self::assertSame('unknown-bridge', $this->resumeRefusal($run)[0]);
    }

    public function test_a_run_of_an_outdated_bridge_is_refused(): void
    {
        $this->bridge->capabilities = null;
        $this->em()->flush();

        self::assertSame(['bridge-outdated', 'Update the bridge to control its runs.'], $this->resumeRefusal($this->runIn(WorkerRunState::Unfinished)));
    }

    public function test_an_interactive_session_is_refused(): void
    {
        $run = $this->seedRun($this->em(), $this->project, bridgeId: $this->bridge->id, state: WorkerRunState::Closed, kind: WorkerRunKind::Interactive);

        self::assertSame('not-controllable', $this->resumeRefusal($run)[0]);
    }

    public function test_a_run_with_no_session_is_refused(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished);
        $run->sessionId = null;
        $this->em()->flush();

        self::assertSame('no-session', $this->resumeRefusal($run)[0]);
    }

    public function test_a_run_whose_card_left_its_column_is_refused(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished);
        $run->cardColumn = 'implementation';
        $this->em()->flush();

        self::assertSame(['card-left', 'The card left the column of this run.'], $this->resumeRefusal($run));
    }

    public function test_a_run_on_a_paused_card_is_refused(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished);
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $holds->hold($run->project, $run->cardId, $run->project->owner);

        self::assertSame(['card-held', 'Agents are paused on this card. Let agents run first.'], $this->resumeRefusal($run));
    }

    public function test_a_malformed_run_id_refuses_the_whole_batch(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished);

        try {
            $this->resume()([(string) $run->id, 'run-7']);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertStringStartsWith('"run-7" is not a valid run ID.', $e->getMessage());
        }
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_an_empty_or_oversized_batch_is_refused(): void
    {
        foreach ([[], array_fill(0, WorkerRunResumeTool::MAX_RUNS + 1, (string) Uuid::v7())] as $runIds) {
            try {
                $this->resume()($runIds);
                self::fail('Expected a refusal.');
            } catch (ToolCallException $e) {
                self::assertSame('Pass from 1 to 50 run ids in runIds.', $e->getMessage());
            }
        }
    }

    public function test_a_stop_writes_a_command_with_its_reason(): void
    {
        $run = $this->runIn(WorkerRunState::Running);

        $result = $this->stop()((string) $run->id, 'wrong branch');

        $command = $this->onlyCommand();
        self::assertSame(['results' => [['runId' => (string) $run->id, 'outcome' => 'stopped', 'commandId' => (string) $command->id]]], $result);
        self::assertSame(BridgeCommandKind::StopRun, $command->kind);
        self::assertSame('wrong branch', $command->reason);
        self::assertSame((string) $this->owner()->id, (string) $command->requestedBy?->id);
    }

    public function test_a_stop_of_an_ended_run_is_refused(): void
    {
        $run = $this->runIn(WorkerRunState::Succeeded);

        $row = $this->stop()((string) $run->id)['results'][0];

        self::assertSame(['refused', 'not-stoppable', 'Only a queued or running run can stop.'], [$row['outcome'], $row['code'] ?? null, $row['message'] ?? null]);
    }

    public function test_a_stop_with_a_long_reason_is_refused(): void
    {
        $run = $this->runIn(WorkerRunState::Running);

        $row = $this->stop()((string) $run->id, str_repeat('x', BridgeCommand::MAX_REASON_LENGTH + 1))['results'][0];

        self::assertSame('reason-too-long', $row['code'] ?? null);
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_stop_of_a_run_of_another_project_is_not_found(): void
    {
        $run = $this->runIn(WorkerRunState::Running, project: $this->other);

        $row = $this->stop()((string) $run->id)['results'][0];

        self::assertSame('not-found', $row['code'] ?? null);
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_cancel_withdraws_the_command_that_waits(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished);
        $command = $this->seedCommand($this->em(), $run, kind: BridgeCommandKind::ResumeRun);

        $result = $this->cancel()((string) $run->id);

        self::assertSame(['results' => [['runId' => (string) $run->id, 'outcome' => 'cancelled', 'commandId' => (string) $command->id]]], $result);
        $this->em()->refresh($command);
        self::assertSame(BridgeCommandState::Cancelled, $command->state);
    }

    public function test_a_cancel_with_nothing_pending_is_refused(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished);
        $this->seedCommand($this->em(), $run, BridgeCommandState::Done, kind: BridgeCommandKind::ResumeRun);

        $row = $this->cancel()((string) $run->id)['results'][0];

        self::assertSame(['refused', 'nothing-pending', 'This run has no command that waits.'], [$row['outcome'], $row['code'] ?? null, $row['message'] ?? null]);
    }

    public function test_a_cancel_of_a_run_of_another_project_is_not_found(): void
    {
        $run = $this->runIn(WorkerRunState::Unfinished, project: $this->other);
        $command = $this->seedCommand($this->em(), $run, kind: BridgeCommandKind::ResumeRun);

        $row = $this->cancel()((string) $run->id)['results'][0];

        self::assertSame('not-found', $row['code'] ?? null);
        $this->em()->refresh($command);
        self::assertSame(BridgeCommandState::Pending, $command->state);
    }

    public function test_a_write_with_an_unbound_token_is_refused(): void
    {
        $run = $this->runIn(WorkerRunState::Running);
        $this->actAsUnboundMcpToken($this->owner());

        $this->expectException(ToolCallException::class);

        $this->stop()((string) $run->id);
    }

    private function runIn(WorkerRunState $state, ?Project $project = null, ?int $exitCode = 0): WorkerRun
    {
        return $this->seedRun($this->em(), $project ?? $this->project, exitCode: $exitCode, bridgeId: $this->bridge->id, state: $state, runKey: Uuid::v7());
    }

    private function owner(): User
    {
        return $this->project->owner;
    }

    /** @return array{string|null, string|null} the code and the message of the one refusal */
    private function resumeRefusal(WorkerRun $run): array
    {
        $row = $this->resume()([(string) $run->id])['results'][0];
        self::assertSame('refused', $row['outcome']);
        self::assertSame(0, $this->countCommands($this->em()));

        return [$row['code'] ?? null, $row['message'] ?? null];
    }

    private function onlyCommand(): BridgeCommand
    {
        $repository = self::getContainer()->get(BridgeCommandRepository::class);
        self::assertInstanceOf(BridgeCommandRepository::class, $repository);
        $commands = $repository->findAll();
        self::assertCount(1, $commands);

        return $commands[0];
    }

    private function resume(): WorkerRunResumeTool
    {
        $tool = self::getContainer()->get(WorkerRunResumeTool::class);
        self::assertInstanceOf(WorkerRunResumeTool::class, $tool);

        return $tool;
    }

    private function stop(): WorkerRunStopTool
    {
        $tool = self::getContainer()->get(WorkerRunStopTool::class);
        self::assertInstanceOf(WorkerRunStopTool::class, $tool);

        return $tool;
    }

    private function cancel(): BridgeCommandCancelTool
    {
        $tool = self::getContainer()->get(BridgeCommandCancelTool::class);
        self::assertInstanceOf(BridgeCommandCancelTool::class, $tool);

        return $tool;
    }
}
