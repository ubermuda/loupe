<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\AcceptProposalCommand;
use App\Module\Insights\Command\AcceptProposalHandler;
use App\Module\Insights\Entity\Proposal;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('insights-proposal-accept')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/proposals/{proposalId}/accept',
    name: 'app_project_analytics_proposal_accept',
    requirements: ['proposalId' => Requirement::UUID],
    methods: ['POST'],
)]
class AcceptProposalController extends AppController
{
    public function __construct(
        private readonly AcceptProposalHandler $acceptProposal,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(proposalId, project)')] Proposal $proposal,
    ): Response {
        try {
            ($this->acceptProposal)(new AcceptProposalCommand($proposal));
            $this->addFlash('success', $this->translator->trans('insights.reports.flash.accepted'));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $key) {
                $this->addFlash('error', $this->translator->trans($key));
            }
        }

        return $this->redirectToRoute('app_project_analytics_reports', self::reportsPage($project, $request));
    }

    /** @return array<string, int|string> the Reports page the form came from */
    private static function reportsPage(Project $project, Request $request): array
    {
        $page = $request->query->getInt('page', 1);

        return $page > 1 ? ['id' => (string) $project->id, 'page' => $page] : ['id' => (string) $project->id];
    }
}
