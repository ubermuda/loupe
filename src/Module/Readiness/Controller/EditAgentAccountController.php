<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\RecordAgentGitHubLoginCommand;
use App\Module\Readiness\Command\RecordAgentGitHubLoginHandler;
use App\Module\Readiness\Form\UpdateAgentAccountFormType;
use App\Module\Readiness\Form\UpdateAgentAccountRequest;
use App\Module\Readiness\Service\ReadinessChecklist;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/readiness/agent-account',
    name: 'app_project_readiness_agent_account',
    methods: ['GET', 'POST'],
)]
final class EditAgentAccountController extends AppController
{
    public function __construct(
        private readonly RecordAgentGitHubLoginHandler $recordLogin,
        private readonly ReadinessChecklist $checklist,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $data = UpdateAgentAccountRequest::fromProject($project);
        $form = $this->createForm(UpdateAgentAccountFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->recordLogin)(new RecordAgentGitHubLoginCommand($project, $data->login));
                $this->addFlash('success', $this->translator->trans('readiness.agent_account.flash.saved'));

                return $this->redirectToRoute('app_project_readiness_agent_account', ['id' => (string) $project->id]);
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->renderFormResponse('@Readiness/edit_agent_account.html.twig', $form, [
            'project' => $project,
            'check' => $this->checklist->agentAccount($project),
        ]);
    }
}
