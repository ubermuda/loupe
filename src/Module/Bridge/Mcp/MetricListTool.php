<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListMetricsCommand;
use App\Module\Bridge\Command\ListMetricsHandler;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/** Lists the metrics that metric_query reads, with the values each one takes. */
#[McpTool(name: self::NAME, description: 'List the metrics that metric_query reads. Each metric has key, units (run, card or both), valueType (money in US dollars, tokens, duration, count or ratio), statistics, groups and description. Pass the key as the metric of metric_query, with one of its units, statistics and groups. A duration in milliseconds is the time of a run, and hours-to-merge is in hours.')]
final readonly class MetricListTool
{
    public const string NAME = 'metric_list';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ListMetricsHandler $listMetrics,
    ) {
    }

    /** @return array{metrics: list<array{key: string, units: list<string>, valueType: string, statistics: list<string>, groups: list<string>, description: string}>} */
    public function __invoke(): array
    {
        try {
            $this->subjects->requireReadableProject();

            return ['metrics' => array_map(static fn (Metric $metric): array => [
                'key' => $metric->value,
                'units' => array_map(static fn (MetricUnit $unit): string => $unit->value, $metric->units()),
                'valueType' => $metric->valueType()->value,
                'statistics' => array_map(static fn (MetricStatistic $statistic): string => $statistic->value, $metric->statistics()),
                'groups' => array_map(static fn (MetricGroup $group): string => $group->value, $metric->groups()),
                'description' => $metric->description(),
            ], ($this->listMetrics)(new ListMetricsCommand())->metrics)];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The metrics could not be listed. The error has been logged.', previous: $e);
        }
    }
}
