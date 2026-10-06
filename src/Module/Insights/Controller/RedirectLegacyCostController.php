<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/cost',
    name: 'app_project_legacy_worker_run_cost',
    methods: ['GET'],
)]
class RedirectLegacyCostController extends AppController
{
    public function __invoke(Project $project, Request $request): Response
    {
        $range = $request->query->getString('range');

        return $this->redirectToRoute('app_project_analytics_metrics', array_filter([
            'id' => (string) $project->id,
            'unit' => MetricUnit::Card->value,
            'metric' => Metric::Cost->value,
            'statistic' => MetricStatistic::Mean->value,
            'range' => MetricRange::tryFrom('all-time' === $range ? MetricRange::All->value : $range)?->value,
            'bucket' => MetricBucket::tryFrom($request->query->getString('group'))?->value,
        ]), Response::HTTP_MOVED_PERMANENTLY);
    }
}
