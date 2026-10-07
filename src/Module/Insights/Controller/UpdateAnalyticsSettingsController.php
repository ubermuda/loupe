<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\UpdateAnalyticsSettingsCommand;
use App\Module\Insights\Command\UpdateAnalyticsSettingsHandler;
use App\Module\Insights\Form\AnalyticsSettingsFormType;
use App\Module\Insights\Form\AnalyticsSettingsRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/reports/settings',
    name: 'app_project_analytics_settings_update',
    methods: ['POST'],
)]
class UpdateAnalyticsSettingsController extends AppController
{
    public function __construct(
        private readonly UpdateAnalyticsSettingsHandler $updateSettings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $data = new AnalyticsSettingsRequest();
        $form = $this->createForm(AnalyticsSettingsFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->updateSettings)(new UpdateAnalyticsSettingsCommand($project, $data->model, $data->effort, $data->collectFullText, subcommandPrograms: $data->subcommandProgramList()));
                $this->addFlash('success', $this->translator->trans('insights.reports.flash.settings_saved'));

                return $this->redirectToRoute('app_project_analytics_reports', ['id' => (string) $project->id]);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward(ListReportsController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            ListReportsController::SETTINGS_FORM => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
