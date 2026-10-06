<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricRange;
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
        private readonly MetricQueryHandler $metricQuery,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        // The query takes only what its metric allows, so the handler never refuses it.
        $query = MetricsQuery::fromQuery($request->query);
        $view = ($this->metricQuery)(new MetricQueryCommand($project, $query->unit, $query->metric, $query->statistic, $query->group, $query->range, $query->bucket));

        return $this->render('@Insights/show_metrics.html.twig', [
            'project' => $project,
            'query' => $query,
            'view' => $view,
            'chart' => MetricChart::build($query->metric, $query->bucket, $view->series),
            'metrics' => Metric::cases(),
            'ranges' => MetricRange::cases(),
            'buckets' => MetricBucket::cases(),
        ]);
    }
}
