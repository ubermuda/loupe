<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Bridge\Mcp\BridgeHostSamplesTool;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeHostSamplesToolTest extends KernelTestCase
{
    use BridgeScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_reads_the_samples_of_the_run_bridge_in_the_run_window_oldest_first(): void
    {
        [$project] = $this->projects('host-samples-window');
        $em = $this->em();
        $bridgeId = Uuid::v4();
        $bridge = $this->seedBridge($em, $project->owner, $bridgeId);
        $run = $this->seedRun($em, $project, bridgeId: $bridgeId);
        foreach (['09:59:59', '10:05:00', '10:00:00', '10:02:00', '10:05:01'] as $time) {
            $this->seedHostSample($bridge, '2026-01-01 '.$time);
        }
        $this->seedHostSample($this->seedBridge($em, $project->owner), '2026-01-01 10:01:00');
        $this->seedHostSample($this->seedBridge($em, $this->user($em, 'host-samples-other-'.uniqid().'@example.com'), $bridgeId), '2026-01-01 10:01:00');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool()((string) $run->id);

        self::assertSame(
            ['2026-01-01T10:00:00+00:00', '2026-01-01T10:02:00+00:00', '2026-01-01T10:05:00+00:00'],
            array_column($result['samples'], 'sampledAt'),
        );
        self::assertSame(
            ['runId' => (string) $run->id, 'bridgeId' => (string) $bridgeId, 'note' => null, 'page' => 1, 'perPage' => 100, 'total' => 3, 'hasMore' => false],
            array_diff_key($result, ['samples' => true]),
        );
    }

    public function test_each_sample_carries_every_field_and_the_mean_of_the_cores(): void
    {
        [$project] = $this->projects('host-samples-fields');
        $em = $this->em();
        $bridgeId = Uuid::v4();
        $bridge = $this->seedBridge($em, $project->owner, $bridgeId);
        $run = $this->seedRun($em, $project, bridgeId: $bridgeId);
        $this->seedHostSample($bridge, '2026-01-01 10:01:00', [10.0, 20.0, 30.5], 1500, 200, 80.5, false);
        $this->seedHostSample($bridge, '2026-01-01 10:02:00', []);
        $this->actAsMcpTokenBoundTo($project);

        $samples = $this->tool()((string) $run->id)['samples'];

        self::assertSame([
            'sampledAt' => '2026-01-01T10:01:00+00:00',
            'cpuPct' => [10.0, 20.0, 30.5],
            'meanCpuPct' => 20.2,
            'memUsed' => 1500,
            'memTotal' => 4000,
            'swapUsed' => 200,
            'batteryPct' => 80.5,
            'onAc' => false,
        ], $samples[0]);
        self::assertNull($samples[1]['meanCpuPct']);
    }

    public function test_it_reads_a_page_at_a_time_and_clamps_the_page_size(): void
    {
        [$project] = $this->projects('host-samples-page');
        $em = $this->em();
        $bridgeId = Uuid::v4();
        $bridge = $this->seedBridge($em, $project->owner, $bridgeId);
        $run = $this->seedRun($em, $project, bridgeId: $bridgeId);
        foreach (['10:01:00', '10:02:00', '10:03:00'] as $time) {
            $this->seedHostSample($bridge, '2026-01-01 '.$time);
        }
        $this->actAsMcpTokenBoundTo($project);

        $first = $this->tool()((string) $run->id, perPage: 2);
        $second = $this->tool()((string) $run->id, page: 2, perPage: 2);
        $large = $this->tool()((string) $run->id, page: 0, perPage: 10_000);

        self::assertSame(['2026-01-01T10:01:00+00:00', '2026-01-01T10:02:00+00:00'], array_column($first['samples'], 'sampledAt'));
        self::assertSame([2, 3, true], [$first['perPage'], $first['total'], $first['hasMore']]);
        self::assertSame(['2026-01-01T10:03:00+00:00'], array_column($second['samples'], 'sampledAt'));
        self::assertFalse($second['hasMore']);
        self::assertSame([1, 500, 3], [$large['page'], $large['perPage'], \count($large['samples'])]);
    }

    public function test_a_run_that_has_not_ended_reads_up_to_now(): void
    {
        [$project] = $this->projects('host-samples-open');
        $em = $this->em();
        $bridgeId = Uuid::v4();
        $bridge = $this->seedBridge($em, $project->owner, $bridgeId);
        $run = $this->seedRun($em, $project, bridgeId: $bridgeId);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $run->startedAt = $now->modify('-10 minutes');
        $run->endedAt = null;
        $em->flush();
        $this->seedHostSample($bridge, $now->modify('-5 minutes')->format('Y-m-d H:i:s'));
        $this->seedHostSample($bridge, $now->modify('+1 hour')->format('Y-m-d H:i:s'));
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(1, $this->tool()((string) $run->id)['total']);
    }

    public function test_a_run_with_no_bridge_reads_an_empty_list_with_a_note(): void
    {
        [$project] = $this->projects('host-samples-no-bridge');
        $run = $this->seedRun($this->em(), $project, kind: WorkerRunKind::Interactive);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool()((string) $run->id);

        self::assertSame([], $result['samples']);
        self::assertNull($result['bridgeId']);
        self::assertSame(0, $result['total']);
        self::assertNotNull($result['note']);
    }

    public function test_a_run_with_no_start_reads_an_empty_list_with_a_note(): void
    {
        [$project] = $this->projects('host-samples-no-start');
        $em = $this->em();
        $bridgeId = Uuid::v4();
        $bridge = $this->seedBridge($em, $project->owner, $bridgeId);
        $run = $this->seedRun($em, $project, bridgeId: $bridgeId);
        $run->startedAt = null;
        $em->flush();
        $this->seedHostSample($bridge, '2026-01-01 10:01:00');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->tool()((string) $run->id);

        self::assertSame([], $result['samples']);
        self::assertSame((string) $bridgeId, $result['bridgeId']);
        self::assertNotNull($result['note']);
    }

    public function test_a_run_of_another_project_is_an_unknown_run(): void
    {
        [$project, $other] = $this->projects('host-samples-scope');
        $foreign = $this->seedRun($this->em(), $other);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(\sprintf('Worker run "%s" not found or not accessible.', $foreign->id), $this->refusal((string) $foreign->id));
    }

    /** @return array{Project, Project} two projects of one owner */
    private function projects(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'mcp-'.$name.'-'.uniqid().'@example.com');

        return [$this->project($em, $owner, 'Bound '.$name), $this->project($em, $owner, 'Other '.$name)];
    }

    private function refusal(string $runId): string
    {
        try {
            $this->tool()($runId);
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a refusal.');
    }

    private function tool(): BridgeHostSamplesTool
    {
        $tool = self::getContainer()->get(BridgeHostSamplesTool::class);
        self::assertInstanceOf(BridgeHostSamplesTool::class, $tool);

        return $tool;
    }
}
