<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ListMetricsCommand;
use App\Module\Bridge\Command\ListMetricsHandler;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Command\ShowMetricsCommand;
use App\Module\Insights\Command\ShowMetricsHandler;
use App\Module\Insights\View\MetricChart;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/metrics',
    name: 'app_project_analytics_metrics',
    methods: ['GET'],
)]
class ShowMetricsController extends AppController
{
    public function __construct(
        private readonly ShowMetricsHandler $showMetrics,
        private readonly ListMetricsHandler $listMetrics,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $query = MetricsQuery::fromQuery($request->query);
        $view = ($this->showMetrics)(new ShowMetricsCommand($project, $query));
        $bucketNames = ($this->listMetrics)(new ListMetricsCommand($project))->bucketNames;
        // A valid name with no time on any run still reads as 0, so the picker keeps it.
        if (null !== $query->bucketName && !\in_array($query->bucketName, $bucketNames, true)) {
            $bucketNames[] = $query->bucketName;
        }
        sort($bucketNames, \SORT_STRING);

        return $this->render('@Insights/show_metrics.html.twig', [
            'project' => $view->project,
            'query' => $query,
            'view' => $view->metrics,
            'groupLabels' => $view->groupLabels,
            'keptRunIds' => $view->keptRunIds,
            'rowLimit' => ShowMetricsHandler::ROW_LIMIT,
            'chart' => MetricChart::build($query->metric, $query->statistic, $query->bucket, $view->metrics->series),
            'metrics' => Metric::standalone(),
            'bucketNames' => $bucketNames,
            'ranges' => MetricRange::cases(),
            'buckets' => MetricBucket::cases(),
        ]);
    }
}
