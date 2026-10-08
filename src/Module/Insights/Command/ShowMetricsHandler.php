<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BridgeLabels;
use Symfony\Component\Uid\Uuid;

final readonly class ShowMetricsHandler
{
    public const int ROW_LIMIT = 100;

    public function __construct(
        private MetricQueryHandler $metricQuery,
        private BridgeLabels $bridgeLabels,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function __invoke(ShowMetricsCommand $command): ShowMetricsView
    {
        $project = $command->project;
        $query = $command->query;
        // The query takes only what its metric allows, so the handler never refuses it.
        $metrics = ($this->metricQuery)(new MetricQueryCommand($project, $query->unit, $query->metric, $query->statistic, $query->group, $query->range, $query->bucket, null));
        $groupLabels = MetricGroup::Bridge === $query->group
            ? $this->bridgeLabels->forOwner($project->owner, array_values(array_filter(array_map(static fn (MetricSeries $series): ?string => $series->group, $metrics->series))))
            : [];
        // A fact outlives its run, so only a run the retention sweep kept gets a link.
        $keptRunIds = MetricUnit::Run === $query->unit
            ? array_flip($this->workerRuns->findExistingIds($project, array_merge(...array_map(
                static fn (MetricSeries $series): array => array_map(static fn (MetricRow $row): Uuid => $row->unitId, \array_slice($series->rows, 0, self::ROW_LIMIT)),
                $metrics->series,
            ))))
            : [];

        return new ShowMetricsView($project, $metrics, $groupLabels, $keptRunIds);
    }
}
