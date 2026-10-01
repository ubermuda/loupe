<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Mcp\BridgeListTool;
use App\Module\Bridge\Mcp\WorkerRunGetTool;
use App\Module\Bridge\Mcp\WorkerRunListTool;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunReadToolsTest extends KernelTestCase
{
    use BridgeScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_list_reads_the_runs_of_the_bound_project_alone(): void
    {
        [$project, $other] = $this->projects('list-scope');
        $run = $this->seedRun($this->em(), $project, cardNumber: 4, output: "first line\n  last line  \n\n", ruleName: 'implement');
        $this->seedRun($this->em(), $other);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->listTool()();

        self::assertSame(1, $result['total']);
        self::assertFalse($result['hasMore']);
        $row = $result['runs'][0];
        self::assertSame((string) $run->id, $row['runId']);
        self::assertSame(4, $row['cardNumber']);
        self::assertSame('implement', $row['rule']);
        self::assertSame('succeeded', $row['state']);
        self::assertSame((string) $run->bridgeId, $row['bridgeId']);
        self::assertSame((string) $run->sessionId, $row['sessionId']);
        self::assertSame('2026-01-01T10:05:00+00:00', $row['endedAt']);
        self::assertSame('last line', $row['reason']);
        self::assertNull($row['pendingCommand']);
    }

    public function test_the_reason_prefers_the_failure_reason_and_is_cut_to_300_characters(): void
    {
        [$project] = $this->projects('list-reason');
        $this->seedRun($this->em(), $project, exitCode: null, failureReason: str_repeat('x', 400), output: 'ignored');
        $this->actAsMcpTokenBoundTo($project);

        $row = $this->listTool()()['runs'][0];

        self::assertSame(str_repeat('x', 300), $row['reason']);
    }

    public function test_a_run_with_no_output_and_no_failure_reason_has_no_reason(): void
    {
        [$project] = $this->projects('list-no-reason');
        $this->seedRun($this->em(), $project, output: " \n ");
        $this->actAsMcpTokenBoundTo($project);

        self::assertNull($this->listTool()()['runs'][0]['reason']);
    }

    public function test_the_list_shows_the_command_that_waits(): void
    {
        [$project] = $this->projects('list-pending');
        $run = $this->seedRun($this->em(), $project, state: WorkerRunState::Running);
        $command = $this->seedCommand($this->em(), $run, kind: BridgeCommandKind::StopRun);
        $this->actAsMcpTokenBoundTo($project);

        $row = $this->listTool()()['runs'][0];

        self::assertSame(['commandId' => (string) $command->id, 'kind' => 'stop-run', 'state' => 'pending'], $row['pendingCommand']);
    }

    public function test_the_list_applies_the_filters_and_pages(): void
    {
        [$project] = $this->projects('list-filters');
        $em = $this->em();
        $kept = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-02 00:00:00'), cardNumber: 7, exitCode: 1, ruleName: 'plan');
        $kept->endedAt = new \DateTimeImmutable('2026-09-10 12:00:00');
        $second = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-01 00:00:00'), cardNumber: 7, ruleName: 'plan', state: WorkerRunState::GaveUp);
        $second->endedAt = new \DateTimeImmutable('2026-09-11 12:00:00');
        $this->seedRun($em, $project, cardNumber: 7, ruleName: 'plan', state: WorkerRunState::Succeeded);
        $this->seedRun($em, $project, cardNumber: 8, exitCode: 1, ruleName: 'plan');
        $em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $first = $this->listTool()(states: ['failed', 'gave-up'], cardNumber: 7, rule: ' plan ', endedAfter: '2026-09-10', endedBefore: '2026-09-12T00:00:00Z', perPage: 1);

        self::assertSame(2, $first['total']);
        self::assertSame(1, $first['perPage']);
        self::assertTrue($first['hasMore']);
        self::assertSame((string) $kept->id, $first['runs'][0]['runId']);

        $last = $this->listTool()(states: ['failed', 'gave-up'], cardNumber: 7, rule: 'plan', endedAfter: '2026-09-10', perPage: 1, page: 2);

        self::assertFalse($last['hasMore']);
        self::assertSame((string) $second->id, $last['runs'][0]['runId']);
    }

    public function test_the_list_filters_by_bridge_and_search(): void
    {
        [$project] = $this->projects('list-bridge');
        $run = $this->seedRun($this->em(), $project, output: 'the deploy key expired');
        $this->seedRun($this->em(), $project, output: 'the deploy key expired');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->listTool()(bridgeId: (string) $run->bridgeId, search: 'deploy');

        self::assertSame([(string) $run->id], array_column($result['runs'], 'runId'));
    }

    public function test_an_unknown_state_is_refused_with_the_valid_states(): void
    {
        [$project] = $this->projects('list-state');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/^Unknown state "broken"\. Use one of: queued, replaced, .*, gave-up, timed-out, lost, closed\.$/');

        $this->listTool()(states: ['failed', 'broken']);
    }

    public function test_a_time_that_is_not_iso_8601_is_refused(): void
    {
        [$project] = $this->projects('list-time');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('endedAfter: "yesterday" is not an ISO 8601 date');

        $this->listTool()(endedAfter: 'yesterday');
    }

    public function test_a_malformed_bridge_id_is_refused(): void
    {
        [$project] = $this->projects('list-bridge-id');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"nope" is not a valid bridge ID.');

        $this->listTool()(bridgeId: 'nope');
    }

    public function test_an_unbound_token_is_refused(): void
    {
        [$project] = $this->projects('list-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->listTool()();
    }

    public function test_get_reads_the_whole_series_oldest_first(): void
    {
        [$project] = $this->projects('get-series');
        $em = $this->em();
        $root = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-01 10:00:00'), state: WorkerRunState::Unfinished);
        $child = $this->continuing($root, new \DateTimeImmutable('2026-09-01 11:00:00'));
        $grandchild = $this->continuing($child, new \DateTimeImmutable('2026-09-01 12:00:00'));
        $branch = $this->continuing($root, new \DateTimeImmutable('2026-09-01 13:00:00'));
        $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-01 10:30:00'));
        $root->triggerEventType = 'pull_request.fix_requested';
        $root->triggerPullRequestNumber = 12;
        $em->persist(new WorkerRunStateChange($root, WorkerRunState::Running, new \DateTimeImmutable('2026-09-01 10:00:05')));
        $em->persist(new WorkerRunStateChange($root, WorkerRunState::Unfinished, new \DateTimeImmutable('2026-09-01 10:04:00')));
        $em->flush();
        $done = $this->seedCommand($em, $root, BridgeCommandState::Done, new \DateTimeImmutable('2026-09-01 10:50:00'), kind: BridgeCommandKind::ResumeRun);
        $waiting = $this->seedCommand($em, $grandchild, requestedAt: new \DateTimeImmutable('2026-09-01 12:30:00'));
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->getTool()((string) $child->id);

        self::assertSame(
            [(string) $root->id, (string) $child->id, (string) $grandchild->id, (string) $branch->id],
            array_column($result['runs'], 'runId'),
        );
        $first = $result['runs'][0];
        self::assertNull($first['continuesRunId']);
        self::assertSame('pull_request.fix_requested', $first['triggerEventType']);
        self::assertSame(12, $first['triggerPullRequestNumber']);
        self::assertSame('worker output', $first['output']);
        self::assertSame([
            ['state' => 'running', 'at' => '2026-09-01T10:00:05+00:00'],
            ['state' => 'unfinished', 'at' => '2026-09-01T10:04:00+00:00'],
        ], $first['stateChanges']);
        self::assertNull($first['pendingCommand']);
        self::assertSame((string) $child->id, $result['runs'][2]['continuesRunId']);
        self::assertSame('pending', $result['runs'][2]['pendingCommand']['state'] ?? null);
        self::assertSame([(string) $done->id, (string) $waiting->id], array_column($result['commands'], 'commandId'));
        self::assertSame([
            'commandId' => (string) $done->id,
            'runId' => (string) $root->id,
            'kind' => 'resume-run',
            'state' => 'done',
            'reason' => null,
            'requestedAt' => '2026-09-01T10:50:00+00:00',
            'expiresAt' => '2026-09-01T11:05:00+00:00',
            'settledAt' => null,
        ], $result['commands'][0]);
    }

    public function test_get_leaves_out_linked_runs_of_another_project(): void
    {
        [$project, $other] = $this->projects('get-cross');
        $em = $this->em();
        $foreignParent = $this->seedRun($em, $other, receivedAt: new \DateTimeImmutable('2026-09-01 09:00:00'), state: WorkerRunState::Unfinished);
        $run = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-01 10:00:00'), state: WorkerRunState::Unfinished);
        $run->continuesRun = $foreignParent;
        $foreignChild = $this->seedRun($em, $other, receivedAt: new \DateTimeImmutable('2026-09-01 11:00:00'), state: WorkerRunState::Unfinished);
        $foreignChild->continuesRun = $run;
        $em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->getTool()((string) $run->id);

        self::assertSame([(string) $run->id], array_column($result['runs'], 'runId'));
    }

    /** A deleted run leaves its continuations with no link back, so each becomes the oldest run of its own branch. */
    public function test_get_after_a_deleted_ancestor_reads_the_branch_of_the_oldest_run_that_remains(): void
    {
        [$project] = $this->projects('get-deleted');
        $em = $this->em();
        $root = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-01 10:00:00'), state: WorkerRunState::Unfinished);
        $child = $this->continuing($root, new \DateTimeImmutable('2026-09-01 11:00:00'));
        $grandchild = $this->continuing($child, new \DateTimeImmutable('2026-09-01 12:00:00'));
        $this->continuing($root, new \DateTimeImmutable('2026-09-01 13:00:00'));
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [(string) $root->id]);
        $em->clear();
        $this->actAsMcpTokenBoundTo($em->find(Project::class, $project->id) ?? throw new \LogicException('The project exists.'));

        $result = $this->getTool()((string) $grandchild->id);

        self::assertSame([(string) $child->id, (string) $grandchild->id], array_column($result['runs'], 'runId'));
        self::assertNull($result['runs'][0]['continuesRunId']);
    }

    public function test_an_impossible_date_is_refused(): void
    {
        [$project] = $this->projects('list-impossible');
        $this->actAsMcpTokenBoundTo($project);

        foreach (['2026-02-31', '2026-09-30T24:30:00Z', '2026-13-01T10:00:00Z'] as $value) {
            try {
                $this->listTool()(endedBefore: $value);
                self::fail(\sprintf('Expected %s to be refused.', $value));
            } catch (ToolCallException $e) {
                self::assertSame(\sprintf('endedBefore: "%s" is not an ISO 8601 date, such as 2026-09-30 or 2026-09-30T14:00:00Z.', $value), $e->getMessage());
            }
        }
    }

    public function test_get_answers_a_run_of_another_project_as_an_unknown_run(): void
    {
        [$project, $other] = $this->projects('get-scope');
        $foreign = $this->seedRun($this->em(), $other);
        $this->actAsMcpTokenBoundTo($project);

        $unknown = '0199a1b2-0000-7000-8000-00000000abcd';
        self::assertSame(\sprintf('Worker run "%s" not found or not accessible.', $unknown), $this->getRefusal($unknown));
        self::assertSame(\sprintf('Worker run "%s" not found or not accessible.', $foreign->id), $this->getRefusal((string) $foreign->id));
    }

    public function test_get_refuses_a_malformed_run_id(): void
    {
        [$project] = $this->projects('get-malformed');
        $this->actAsMcpTokenBoundTo($project);

        self::assertStringStartsWith('"12" is not a valid run ID.', $this->getRefusal('12'));
    }

    public function test_the_bridge_list_reads_the_bridges_that_follow_the_project(): void
    {
        [$project, $other] = $this->projects('bridge-list');
        $em = $this->em();
        $live = $this->seedBridge($em, $project->owner, projects: [(string) $project->id], cliVersion: '1.6.0', workerPools: [['name' => 'default', 'size' => 2, 'inUse' => 1, 'queued' => 0]], workerPoolsReportedAt: new \DateTimeImmutable('2026-09-30 11:00:00'));
        $live->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $live->pauseRequested = true;
        $quiet = $this->seedBridge($em, $project->owner, projects: [(string) $project->id], lastSeenAt: new \DateTimeImmutable('-1 day'));
        $this->seedBridge($em, $project->owner, projects: [(string) $other->id]);
        $open = $this->seedRun($em, $project, cardNumber: 3, ruleName: 'plan', bridgeId: $live->id, state: WorkerRunState::Running);
        $this->seedRun($em, $project, bridgeId: $live->id, state: WorkerRunState::Succeeded);
        $this->seedRun($em, $other, bridgeId: $live->id, state: WorkerRunState::Running);
        $em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $bridges = [];
        foreach ($this->bridgeTool()()['bridges'] as $row) {
            $bridges[$row['bridgeId']] = $row;
        }

        self::assertEqualsCanonicalizing([$live->id->toRfc4122(), $quiet->id->toRfc4122()], array_keys($bridges));
        $row = $bridges[$live->id->toRfc4122()];
        self::assertSame('live', $row['liveness']);
        self::assertSame('1.6.0', $row['cliVersion']);
        self::assertTrue($row['pauseRequested']);
        self::assertNull($row['pausedReported']);
        self::assertTrue($row['takesCommands']);
        self::assertSame([['name' => 'default', 'size' => 2, 'inUse' => 1, 'queued' => 0]], $row['workerPools']);
        self::assertSame('2026-09-30T11:00:00+00:00', $row['workerPoolsReportedAt']);
        self::assertSame([['runId' => (string) $open->id, 'cardNumber' => 3, 'rule' => 'plan', 'state' => 'running']], $row['openRuns']);
        self::assertSame('quiet', $bridges[$quiet->id->toRfc4122()]['liveness']);
        self::assertFalse($bridges[$quiet->id->toRfc4122()]['takesCommands']);
        self::assertSame([], $bridges[$quiet->id->toRfc4122()]['openRuns']);
    }

    /** @return array{Project, Project} two projects of one owner */
    private function projects(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'mcp-'.$name.'-'.uniqid().'@example.com');

        return [$this->project($em, $owner, 'Bound '.$name), $this->project($em, $owner, 'Other '.$name)];
    }

    private function continuing(WorkerRun $run, \DateTimeImmutable $receivedAt): WorkerRun
    {
        $next = $this->seedRun($this->em(), $run->project, receivedAt: $receivedAt, bridgeId: $run->bridgeId, cardId: $run->cardId, state: WorkerRunState::Unfinished, runKey: Uuid::v7());
        $next->continuesRun = $run;
        $next->resumeIndex = ($run->resumeIndex ?? 0) + 1;
        $this->em()->flush();

        return $next;
    }

    private function getRefusal(string $runId): string
    {
        try {
            $this->getTool()($runId);
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a refusal.');
    }

    private function listTool(): WorkerRunListTool
    {
        $tool = self::getContainer()->get(WorkerRunListTool::class);
        self::assertInstanceOf(WorkerRunListTool::class, $tool);

        return $tool;
    }

    private function getTool(): WorkerRunGetTool
    {
        $tool = self::getContainer()->get(WorkerRunGetTool::class);
        self::assertInstanceOf(WorkerRunGetTool::class, $tool);

        return $tool;
    }

    private function bridgeTool(): BridgeListTool
    {
        $tool = self::getContainer()->get(BridgeListTool::class);
        self::assertInstanceOf(BridgeListTool::class, $tool);

        return $tool;
    }
}
