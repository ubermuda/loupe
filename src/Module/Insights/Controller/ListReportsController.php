<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Metric\Metric;
use App\Module\Insights\Command\ListReportsCommand;
use App\Module\Insights\Command\ListReportsHandler;
use App\Module\Insights\Form\AnalyticsSettingsFormType;
use App\Module\Insights\Form\AnalyticsSettingsRequest;
use App\Module\Insights\Form\StartAnalysisFormType;
use App\Module\Insights\Form\StartAnalysisRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/reports',
    name: 'app_project_analytics_reports',
    methods: ['GET'],
)]
class ListReportsController extends AppController
{
    public const string START_FORM = 'startForm';
    public const string SETTINGS_FORM = 'settingsForm';
    public const string REFUSED_DISMISS_FORM = 'refusedDismissForm';

    public function __construct(
        private readonly ListReportsHandler $listReports,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $view = ($this->listReports)(new ListReportsCommand($project));

        return $this->render('@Insights/list_reports.html.twig', [
            'project' => $view->project,
            'analyses' => $view->analyses,
            'settings' => $view->settings,
            'costMetric' => Metric::Cost,
            'startForm' => $this->getInjectedFormView($request, self::START_FORM)
                ?? $this->createForm(StartAnalysisFormType::class, new StartAnalysisRequest())->createView(),
            'settingsForm' => $this->getInjectedFormView($request, self::SETTINGS_FORM)
                ?? $this->createForm(AnalyticsSettingsFormType::class, AnalyticsSettingsRequest::fromView($view->settings))->createView(),
            'refusedDismissForm' => $this->getInjectedFormView($request, self::REFUSED_DISMISS_FORM),
        ]);
    }
}
