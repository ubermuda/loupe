<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
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
    public function __invoke(Project $project): Response
    {
        return $this->render('@Insights/show_metrics.html.twig', [
            'project' => $project,
        ]);
    }
}
