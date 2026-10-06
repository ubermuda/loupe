<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/experiments',
    name: 'app_project_legacy_experiments',
    defaults: ['target' => 'app_project_analytics_experiments'],
    methods: ['GET'],
)]
#[Route(
    '/projects/{id:project}/worker-runs/experiments/{experiment}',
    name: 'app_project_legacy_experiment',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    defaults: ['target' => 'app_project_analytics_experiment'],
    methods: ['GET'],
)]
#[Route(
    '/projects/{id:project}/worker-runs/experiments/{experiment}/cards',
    name: 'app_project_legacy_experiment_cards',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    defaults: ['target' => 'app_project_analytics_experiment_cards'],
    methods: ['GET'],
)]
class RedirectLegacyExperimentsController extends AppController
{
    public function __invoke(Project $project, Request $request, string $target, ?string $experiment = null): Response
    {
        $url = $this->generateUrl($target, null === $experiment ? ['id' => (string) $project->id] : ['id' => (string) $project->id, 'experiment' => $experiment]);
        $query = $request->getQueryString();

        return new RedirectResponse(null === $query ? $url : $url.'?'.$query, Response::HTTP_MOVED_PERMANENTLY);
    }
}
