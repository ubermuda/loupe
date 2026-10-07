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
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
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
        $run = $this->seedRun($this->em(), $project, cardNumber: 4, output: "first line\n  last line  \n\n", workKind: 'implement');
        $this->seedRun($this->em(), $other);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->listTool()();

        self::assertSame(1, $result['total']);
        self::assertFalse($result['hasMore']);
        $row = $result['runs'][0];
        self::assertSame((string) $run->id, $row['runId']);
        self::assertSame(4, $row['cardNumber']);
        self::assertSame('implement', $row['workKind']);
        self::assertSame('succeeded', $row['state']);
        self::assertSame((string) $run->bridgeId, $row['bridgeId']);
        self::assertSame((string) $run->sessionId, $row['sessionId']);
        self::assertSame('2026-01-01T10:05:00+00:00', $row['endedAt']);
        self::assertSame('last line', $row['reason']);
        self::assertNull($row['pendingCommand']);
    }

    public function test_each_row_carries_the_usage_the_experiment_and_the_metrics_of_its_run(): void
    {
        [$project] = $this->projects('list-usage');
        $em = $this->em();
        $used = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-03 00:00:00'));
        $used->experiment = 'prompt-length';
        $used->variant = 'short';
        $this->seedUsage($em, $used, model: 'claude-opus-5-5', costUsd: '0.500000', inputTokens: 1000);
        $this->seedUsage($em, $used, model: 'claude-haiku-5', source: WorkerRunUsageSource::Estimated, costUsd: null);
        $bare = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-02 00:00:00'));
        $factless = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-01 00:00:00'));
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = ?', [(string) $factless->id]);
        $this->actAsMcpTokenBoundTo($project);

        [$usedRow, $bareRow, $factlessRow] = $this->listTool()()['runs'];

        self::assertSame((string) $used->id, $usedRow['runId']);
        self::assertSame([
            ['model' => 'claude-haiku-5', 'source' => 'estimated', 'inputTokens' => 100, 'outputTokens' => 20, 'cacheReadTokens' => 300, 'cacheWriteTokens' => 40, 'costUsd' => null],
            ['model' => 'claude-opus-5-5', 'source' => 'reported', 'inputTokens' => 1000, 'outputTokens' => 20, 'cacheReadTokens' => 300, 'cacheWriteTokens' => 40, 'costUsd' => '0.500000'],
        ], $usedRow['usage']);
        self::assertSame('claude-opus-5-5', $usedRow['model']);
        self::assertSame('prompt-length', $usedRow['experiment']);
        self::assertSame('short', $usedRow['variant']);
        self::assertSame(['durationMs' => 300_000, 'costUsd' => null, 'tokensIn' => 1100, 'tokensOut' => 40, 'tokensCacheRead' => 600, 'tokensCacheWrite' => 80, 'toolTimeMs' => null, 'modelTimeMs' => null, 'toolCalls' => null, 'failedCalls' => null, 'longestCallMs' => null, 'idleGapMs' => null, 'subagentMs' => null, 'peakContextTokens' => null], $usedRow['metrics']);

        self::assertSame([], $bareRow['usage']);
        self::assertNull($bareRow['model']);
        self::assertNull($bareRow['experiment']);
        self::assertSame(['durationMs' => 300_000, 'costUsd' => null, 'tokensIn' => null, 'tokensOut' => null, 'tokensCacheRead' => null, 'tokensCacheWrite' => null, 'toolTimeMs' => null, 'modelTimeMs' => null, 'toolCalls' => null, 'failedCalls' => null, 'longestCallMs' => null, 'idleGapMs' => null, 'subagentMs' => null, 'peakContextTokens' => null], $bareRow['metrics']);

        self::assertSame((string) $factless->id, $factlessRow['runId']);
        self::assertNull($factlessRow['metrics']);
        self::assertNull($factlessRow['model']);

        $detail = $this->getTool()((string) $factless->id)['runs'][0];
        self::assertSame([], $detail['usage']);
        self::assertNull($detail['metrics']);
        self::assertSame($usedRow['usage'], $this->getTool()((string) $used->id)['runs'][0]['usage']);
    }

    public function test_the_metrics_give_the_cost_in_dollars_when_every_usage_row_has_a_price(): void
    {
        [$project] = $this->projects('list-cost');
        $run = $this->seedRun($this->em(), $project);
        $this->seedUsage($this->em(), $run, costUsd: '1.250000');
        $this->seedUsage($this->em(), $run, model: 'claude-haiku-5', costUsd: '0.750000');
        $this->actAsMcpTokenBoundTo($project);

        $row = $this->listTool()()['runs'][0];

        self::assertSame(2.0, $row['metrics']['costUsd'] ?? null);
        self::assertSame(['durationMs' => 300_000, 'costUsd' => 2.0, 'tokensIn' => 200, 'tokensOut' => 40, 'tokensCacheRead' => 600, 'tokensCacheWrite' => 80, 'toolTimeMs' => null, 'modelTimeMs' => null, 'toolCalls' => null, 'failedCalls' => null, 'longestCallMs' => null, 'idleGapMs' => null, 'subagentMs' => null, 'peakContextTokens' => null], $this->getTool()((string) $run->id)['runs'][0]['metrics']);
    }

    public function test_the_metrics_carry_the_timing_and_the_tool_calls_of_the_run(): void
    {
        [$project] = $this->projects('list-timing');
        $em = $this->em();
        $run = $this->seedRun($em, $project);
        $this->seedToolCall($run, 1, 'Bash');
        $this->seedToolCall($run, 2, 'Agent');
        $run->toolTimeMs = 4000;
        $run->idleGapMs = 6000;
        $run->peakContextTokens = 150_000;
        $em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $expected = ['durationMs' => 300_000, 'costUsd' => null, 'tokensIn' => null, 'tokensOut' => null, 'tokensCacheRead' => null, 'tokensCacheWrite' => null, 'toolTimeMs' => 4000, 'modelTimeMs' => 290_000, 'toolCalls' => 2, 'failedCalls' => 0, 'longestCallMs' => 1500, 'idleGapMs' => 6000, 'subagentMs' => 1500, 'peakContextTokens' => 150_000];
        self::assertSame($expected, $this->listTool()()['runs'][0]['metrics']);
        self::assertSame($expected, $this->getTool()((string) $run->id)['runs'][0]['metrics']);
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

    public function test_each_row_names_the_kind_of_its_run(): void
    {
        [$project] = $this->projects('list-kind');
        $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('2026-09-01 00:00:00'), workKind: 'plan');
        $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('2026-09-02 00:00:00'), exitCode: -1, workKind: 'sync', kind: WorkerRunKind::Command);
        $this->actAsMcpTokenBoundTo($project);

        $rows = $this->listTool()()['runs'];

        self::assertSame([['sync', 'command', null], ['plan', 'worker']], array_map(
            static fn (array $row): array => 'command' === $row['kind'] ? [$row['workKind'], $row['kind'], $row['sessionId']] : [$row['workKind'], $row['kind']],
            $rows,
        ));
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
        $kept = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-02 00:00:00'), cardNumber: 7, exitCode: 1, workKind: 'plan');
        $kept->endedAt = new \DateTimeImmutable('2026-09-10 12:00:00');
        $second = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-01 00:00:00'), cardNumber: 7, workKind: 'plan', state: WorkerRunState::GaveUp);
        $second->endedAt = new \DateTimeImmutable('2026-09-11 12:00:00');
        $this->seedRun($em, $project, cardNumber: 7, workKind: 'plan', state: WorkerRunState::Succeeded);
        $this->seedRun($em, $project, cardNumber: 8, exitCode: 1, workKind: 'plan');
        $em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $first = $this->listTool()(states: ['failed', 'gave-up'], cardNumber: 7, workKind: ' plan ', endedAfter: '2026-09-10', endedBefore: '2026-09-12T00:00:00Z', perPage: 1);

        self::assertSame(2, $first['total']);
        self::assertSame(1, $first['perPage']);
        self::assertTrue($first['hasMore']);
        self::assertSame((string) $kept->id, $first['runs'][0]['runId']);

        $last = $this->listTool()(states: ['failed', 'gave-up'], cardNumber: 7, workKind: 'plan', endedAfter: '2026-09-10', perPage: 1, page: 2);

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
        self::assertSame('plan', $first['workKind']);
        self::assertNull($first['workRequestId']);
        self::assertNull($first['ruleId']);
        self::assertArrayNotHasKey('triggerEventType', $first);
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
        self::assertNull($result['runs'][0]['continuesRunId']);
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
        $live->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_RERUN_COMMAND];
        $live->pauseRequested = true;
        $live->name = 'laptop';
        $live->requestedName = 'laptop';
        $quiet = $this->seedBridge($em, $project->owner, projects: [(string) $project->id], lastSeenAt: new \DateTimeImmutable('-1 day'));
        $quiet->requestedName = 'laptop';
        $this->seedBridge($em, $project->owner, projects: [(string) $other->id]);
        $open = $this->seedRun($em, $project, cardNumber: 3, workKind: 'plan', bridgeId: $live->id, state: WorkerRunState::Running);
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
        self::assertSame('laptop', $row['name']);
        self::assertSame('laptop', $row['requestedName']);
        self::assertTrue($row['pauseRequested']);
        self::assertNull($row['pausedReported']);
        self::assertTrue($row['takesCommands']);
        self::assertTrue($row['takesReruns']);
        self::assertSame([['name' => 'default', 'size' => 2, 'inUse' => 1, 'queued' => 0]], $row['workerPools']);
        self::assertSame('2026-09-30T11:00:00+00:00', $row['workerPoolsReportedAt']);
        self::assertSame([['runId' => (string) $open->id, 'subjectType' => 'card', 'subjectId' => (string) $open->subjectId, 'cardNumber' => 3, 'workKind' => 'plan', 'state' => 'running']], $row['openRuns']);
        self::assertSame('quiet', $bridges[$quiet->id->toRfc4122()]['liveness']);
        self::assertNull($bridges[$quiet->id->toRfc4122()]['name']);
        self::assertSame('laptop', $bridges[$quiet->id->toRfc4122()]['requestedName']);
        self::assertFalse($bridges[$quiet->id->toRfc4122()]['takesCommands']);
        self::assertFalse($bridges[$quiet->id->toRfc4122()]['takesReruns']);
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
        $next = $this->seedRun($this->em(), $run->project, receivedAt: $receivedAt, bridgeId: $run->bridgeId, cardId: $run->subjectId, state: WorkerRunState::Unfinished, runKey: Uuid::v7());
        $next->continuesRun = $run;
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
