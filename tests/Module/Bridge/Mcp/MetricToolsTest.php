<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Bridge\Mcp\MetricListTool;
use App\Module\Bridge\Mcp\MetricQueryTool;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MetricToolsTest extends KernelTestCase
{
    use BridgeScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_list_gives_every_metric_with_its_combinations(): void
    {
        [$project] = $this->projects('metric-list');
        $this->actAsMcpTokenBoundTo($project);

        $metrics = $this->listTool()()['metrics'];

        self::assertSame(array_map(static fn (Metric $metric): string => $metric->value, Metric::cases()), array_column($metrics, 'key'));
        $stopRate = $metrics[array_search('stop-rate', array_column($metrics, 'key'), true)];
        self::assertSame([
            'key' => 'stop-rate',
            'units' => ['run'],
            'valueType' => 'ratio',
            'statistics' => ['mean', 'count'],
            'groups' => ['stage', 'model', 'variant', 'card-type', 'bridge', 'none'],
            'description' => Metric::StopRate->description(),
        ], $stopRate);
    }

    public function test_the_list_refuses_an_unbound_token(): void
    {
        [$project] = $this->projects('metric-list-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->listTool()();
    }

    public function test_the_query_reads_the_runs_of_the_bound_project_with_the_defaults(): void
    {
        [$project, $other] = $this->projects('metric-query');
        $ended = new \DateTimeImmutable('-2 days')->setTime(9, 0);
        $older = $this->fact($project, $ended->modify('-1 hour'), 1_500_000, cardNumber: 3);
        $newer = $this->fact($project, $ended, 500_000, cardNumber: 4);
        $this->fact($project, new \DateTimeImmutable('-40 days'), 9_000_000);
        $this->fact($other, $ended, 7_000_000);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'sum');

        self::assertSame(['run', 'cost', 'sum', 'none', 'thirty-days', 'week'], [$result['unit'], $result['metric'], $result['statistic'], $result['group'], $result['range'], $result['bucket']]);
        self::assertCount(1, $result['series']);
        $series = $result['series'][0];
        self::assertNull($series['group']);
        self::assertSame(['value' => 2.0, 'rows' => 2], $series['total']);
        self::assertSame([['start' => MetricBucket::Week->startOf($ended)->format(\DATE_ATOM), 'value' => 2.0, 'rows' => 2]], $series['points']);
        self::assertSame(2, $series['rowsTotal']);
        self::assertSame([
            ['id' => (string) $newer, 'cardNumber' => 4, 'time' => $ended->format(\DATE_ATOM), 'value' => 0.5],
            ['id' => (string) $older, 'cardNumber' => 3, 'time' => $ended->modify('-1 hour')->format(\DATE_ATOM), 'value' => 1.5],
        ], $series['rows']);
    }

    public function test_the_query_gives_at_most_100_rows_per_series_newest_first(): void
    {
        [$project] = $this->projects('metric-cap');
        $start = new \DateTimeImmutable('-10 days');
        for ($i = 0; $i < 101; ++$i) {
            $this->fact($project, $start->modify(\sprintf('+%d minutes', $i)), 1_000);
        }
        $this->actAsMcpTokenBoundTo($project);

        $series = $this->queryTool()(unit: 'run', metric: 'duration', statistic: 'count', range: 'all', bucket: 'day')['series'][0];

        self::assertCount(100, $series['rows']);
        self::assertSame(101, $series['rowsTotal']);
        self::assertSame(['value' => 101, 'rows' => 101], $series['total']);
        self::assertGreaterThan($series['rows'][1]['time'], $series['rows'][0]['time']);
    }

    public function test_an_unknown_value_is_refused_with_the_valid_values(): void
    {
        [$project] = $this->projects('metric-unknown');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            'Unknown metric "speed". Use one of: cost, input-tokens, output-tokens, cache-read-tokens, cache-write-tokens, duration, runs, stop-rate, merge-rate, fix-rounds, hours-to-merge.',
            $this->refusal(unit: 'run', metric: 'speed', statistic: 'sum'),
        );
        self::assertSame(
            'Unknown bucket "year". Use one of: day, week, month.',
            $this->refusal(unit: 'run', metric: 'cost', statistic: 'sum', bucket: 'year'),
        );
    }

    public function test_a_refused_combination_names_the_field_and_the_allowed_values(): void
    {
        [$project] = $this->projects('metric-combination');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            'The metric stop-rate does not take the unit "card". Use one of: run. The metric stop-rate does not take the statistic "sum". Use one of: mean, count. metric_list lists the valid combinations.',
            $this->refusal(unit: 'card', metric: 'stop-rate', statistic: 'sum'),
        );
    }

    public function test_the_query_refuses_an_unbound_token(): void
    {
        [$project] = $this->projects('metric-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->queryTool()(unit: 'run', metric: 'cost', statistic: 'sum');
    }

    /** @return array{Project, Project} two projects of one owner */
    private function projects(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'mcp-'.$name.'-'.uniqid().'@example.com');

        return [$this->project($em, $owner, 'Bound '.$name), $this->project($em, $owner, 'Other '.$name)];
    }

    private function fact(Project $project, \DateTimeImmutable $endedAt, int $cost, ?int $cardNumber = null): Uuid
    {
        $runId = Uuid::v7();
        $this->em()->getConnection()->insert('bridge_worker_run_facts', [
            'run_id' => (string) $runId,
            'project_id' => (string) $project->id,
            'subject_type' => 'card',
            'subject_id' => (string) Uuid::v7(),
            'card_number' => $cardNumber,
            'kind' => 'worker',
            'work_kind' => 'implement',
            'outcome' => 'succeeded',
            'started_at' => $endedAt->modify('-5 minutes')->format('Y-m-d H:i:s'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s'),
            'received_at' => $endedAt->format('Y-m-d H:i:s'),
            'duration_ms' => 300_000,
            'cost_micro_usd' => $cost,
            'usage_source' => 'reported',
        ]);

        return $runId;
    }

    private function refusal(string $unit, string $metric, string $statistic, string $bucket = 'week'): string
    {
        try {
            $this->queryTool()(unit: $unit, metric: $metric, statistic: $statistic, bucket: $bucket);
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a refusal.');
    }

    private function listTool(): MetricListTool
    {
        $tool = self::getContainer()->get(MetricListTool::class);
        self::assertInstanceOf(MetricListTool::class, $tool);

        return $tool;
    }

    private function queryTool(): MetricQueryTool
    {
        $tool = self::getContainer()->get(MetricQueryTool::class);
        self::assertInstanceOf(MetricQueryTool::class, $tool);

        return $tool;
    }
}
