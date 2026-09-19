<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Command\UpdateProjectAllowedOriginsCommand;
use App\Module\Project\Command\UpdateProjectAllowedOriginsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Form\UpdateProjectAllowedOriginsFormType;
use App\Module\Project\Form\UpdateProjectAllowedOriginsRequest;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/allowed-origins',
    name: 'app_project_allowed_origins_update',
    methods: ['POST'],
)]
final class UpdateProjectAllowedOriginsController extends AppController
{
    public function __construct(
        private readonly UpdateProjectAllowedOriginsHandler $updateAllowedOrigins,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $data = new UpdateProjectAllowedOriginsRequest();
        $form = $this->createForm(UpdateProjectAllowedOriginsFormType::class, $data, [
            'action' => $this->generateUrl('app_project_allowed_origins_update', ['id' => (string) $project->id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->updateAllowedOrigins)(new UpdateProjectAllowedOriginsCommand($project, $data->origins ?? ''));
                $this->addFlash('success', $this->translator->trans('project.allowed_origins.flash.saved'));

                return $this->redirect($this->generateUrl('app_project_connect', ['id' => (string) $project->id]).'#site-review-widget');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward(ConnectAgentController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            ConnectAgentController::ALLOWED_ORIGINS_FORM => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
