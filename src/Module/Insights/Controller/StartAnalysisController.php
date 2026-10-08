<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ListExperimentsCommand;
use App\Module\Bridge\Command\ListExperimentsHandler;
use App\Module\Insights\Command\StartAnalysisCommand;
use App\Module\Insights\Command\StartAnalysisHandler;
use App\Module\Insights\Form\StartAnalysisFormType;
use App\Module\Insights\Form\StartAnalysisRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/reports/analyses',
    name: 'app_project_analytics_analysis_start',
    methods: ['POST'],
)]
class StartAnalysisController extends AppController
{
    public function __construct(
        private readonly StartAnalysisHandler $startAnalysis,
        private readonly ListExperimentsHandler $listExperiments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $data = new StartAnalysisRequest();
        $form = $this->createForm(StartAnalysisFormType::class, $data, [
            'experiments' => array_column(($this->listExperiments)(new ListExperimentsCommand($project))->experiments, 'name'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->startAnalysis)(new StartAnalysisCommand(
                    project: $project,
                    topic: $data->topic ?? throw new \LogicException('The topic is required after validation.'),
                    range: $data->range ?? throw new \LogicException('The range is required after validation.'),
                    model: $data->model,
                    effort: $data->effort,
                    experiment: $data->experiment,
                ));
                $this->addFlash('success', $this->translator->trans('insights.reports.flash.started'));

                return $this->redirectToRoute('app_project_analytics_reports', ['id' => (string) $project->id]);
            } catch (DomainErrors $e) {
                // The work request can refuse a field this form does not show.
                foreach ($e->errors as $field => $key) {
                    ($form->has($field) ? $form->get($field) : $form)->addError(new FormError($this->translator->trans($key)));
                }
            }
        }

        return $this->forward(ListReportsController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            ListReportsController::START_FORM => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
