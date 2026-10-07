<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\DismissProposalCommand;
use App\Module\Insights\Command\DismissProposalHandler;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Form\DismissProposalFormType;
use App\Module\Insights\Form\DismissProposalRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/proposals/{proposalId}/dismiss',
    name: 'app_project_analytics_proposal_dismiss',
    requirements: ['proposalId' => Requirement::UUID],
    methods: ['POST'],
)]
class DismissProposalController extends AppController
{
    public function __construct(
        private readonly DismissProposalHandler $dismissProposal,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(proposalId, project)')] Proposal $proposal,
    ): Response {
        $data = new DismissProposalRequest();
        $form = $this->formFactory->createNamed(DismissProposalFormType::nameFor($proposal), DismissProposalFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->dismissProposal)(new DismissProposalCommand($proposal, $data->reason));
                $this->addFlash('success', $this->translator->trans('insights.reports.flash.dismissed'));

                return $this->redirectToRoute('app_project_analytics_reports', self::reportsPage($project, $request));
            } catch (DomainErrors $e) {
                if (!\in_array(DismissProposalHandler::REASON_TOO_LONG, $e->errors, true)) {
                    // The proposal is no longer open, so the page has no form to show the error on.
                    foreach ($e->errors as $key) {
                        $this->addFlash('error', $this->translator->trans($key));
                    }

                    return $this->redirectToRoute('app_project_analytics_reports', self::reportsPage($project, $request));
                }
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward(ListReportsController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            ListReportsController::REFUSED_DISMISS_FORM => $form->createView(),
        ], ['page' => max(1, $request->query->getInt('page', 1))])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array<string, int|string> the Reports page the form came from */
    private static function reportsPage(Project $project, Request $request): array
    {
        $page = $request->query->getInt('page', 1);

        return $page > 1 ? ['id' => (string) $project->id, 'page' => $page] : ['id' => (string) $project->id];
    }
}
