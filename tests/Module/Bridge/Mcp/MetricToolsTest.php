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

        self::assertSame(array_map(static fn (Metric $metric): string => $metric->value, Metric::standalone()), array_column($metrics, 'key'));
        $stopRate = array_column($metrics, null, 'key')['stop-rate'];
        self::assertSame([
            'key' => 'stop-rate',
            'units' => ['run'],
            'valueType' => 'ratio',
            'statistics' => ['mean', 'count'],
            'groups' => ['stage', 'model', 'variant', 'card-type', 'bridge', 'harness', 'account', 'none'],
            'description' => Metric::StopRate->description(),
        ], $stopRate);
    }

    public function test_the_list_adds_one_entry_for_each_bucket_of_the_project(): void
    {
        [$project, $other] = $this->projects('metric-list-buckets');
        $this->runWithBuckets($project, ['tests' => 10, 'other' => 5]);
        $this->runWithBuckets($project, ['tests' => 20, 'build' => 30]);
        $this->runWithBuckets($other, ['elsewhere' => 1]);
        $this->actAsMcpTokenBoundTo($project);

        $metrics = $this->listTool()()['metrics'];

        $keys = array_column($metrics, 'key');
        self::assertSame(['bucket-time:build', 'bucket-time:other', 'bucket-time:tests'], \array_slice($keys, -3));
        self::assertNotContains('bucket-time', $keys);
        self::assertNotContains('bucket-time:elsewhere', $keys);
        self::assertSame([
            'key' => 'bucket-time:build',
            'units' => ['run', 'card'],
            'valueType' => 'duration',
            'statistics' => ['median', 'mean', 'sum', 'p90', 'count'],
            'groups' => ['stage', 'model', 'variant', 'card-type', 'bridge', 'harness', 'account', 'none'],
            'description' => Metric::BucketTime->description(),
        ], array_column($metrics, null, 'key')['bucket-time:build']);
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
            'Unknown metric "speed". Use one of: cost, input-tokens, output-tokens, cache-read-tokens, cache-write-tokens, duration, runs, stop-rate, merge-rate, fix-rounds, hours-to-merge, bucket-time:<name>.',
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

    public function test_a_bucket_time_run_has_the_time_of_the_bucket_zero_or_unknown(): void
    {
        [$project] = $this->projects('metric-bucket-run');
        $ended = new \DateTimeImmutable('-2 days')->setTime(9, 0);
        $inBucket = $this->fact($project, $ended, 1, runId: $this->runWithBuckets($project, ['tests' => 4000, 'other' => 1000]));
        $otherBucket = $this->fact($project, $ended->modify('-1 hour'), 1, runId: $this->runWithBuckets($project, ['other' => 700]));
        $noRows = $this->fact($project, $ended->modify('-2 hours'), 1);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'bucket-time:tests', statistic: 'sum');

        self::assertSame('bucket-time:tests', $result['metric']);
        $series = $result['series'][0];
        self::assertSame(['value' => 4000, 'rows' => 2], $series['total']);
        $values = array_column($series['rows'], 'value', 'id');
        self::assertSame([(string) $inBucket => 4000, (string) $otherBucket => 0, (string) $noRows => null], $values);
    }

    public function test_a_bucket_time_without_a_valid_name_is_refused(): void
    {
        [$project] = $this->projects('metric-bucket-name');
        $this->actAsMcpTokenBoundTo($project);

        foreach (['bucket-time', 'bucket-time:', 'bucket-time:Tests', 'bucket-time:a b', 'bucket-time:'.str_repeat('a', 65)] as $metric) {
            self::assertStringContainsString('needs the name of a bucket', $this->refusal(unit: 'run', metric: $metric, statistic: 'sum'), $metric);
        }
        self::assertStringContainsString('Unknown metric "cost:tests"', $this->refusal(unit: 'run', metric: 'cost:tests', statistic: 'sum'));
    }

    public function test_a_bucket_time_name_of_64_characters_is_read(): void
    {
        [$project] = $this->projects('metric-bucket-long');
        $name = str_repeat('a', 64);
        $this->fact($project, new \DateTimeImmutable('-1 day'), 1, runId: $this->runWithBuckets($project, [$name => 5]));
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->queryTool()(unit: 'run', metric: 'bucket-time:'.$name, statistic: 'sum');

        self::assertSame(['value' => 5, 'rows' => 1], $result['series'][0]['total']);
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

    /**
     * @param array<string, int> $times
     *
     * @return Uuid the id of a stored run of the project that has the bucket rows
     */
    private function runWithBuckets(Project $project, array $times): Uuid
    {
        $run = $this->seedRun($this->em(), $project);
        // The fact listener wrote a fact row for the run, and the test writes its own.
        $this->em()->getConnection()->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = :run', ['run' => (string) $run->id]);
        foreach ($times as $bucket => $ms) {
            $this->em()->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $run->id, 'bucket' => (string) $bucket, 'ms' => $ms]);
        }

        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }

    private function fact(Project $project, \DateTimeImmutable $endedAt, int $cost, ?int $cardNumber = null, ?Uuid $runId = null): Uuid
    {
        $runId ??= Uuid::v7();
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
