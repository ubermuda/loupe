<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\ShowReadinessCommand;
use App\Module\Readiness\Command\ShowReadinessHandler;
use App\Module\Readiness\Command\UpdateReadinessSettingsCommand;
use App\Module\Readiness\Command\UpdateReadinessSettingsHandler;
use App\Module\Readiness\Form\UpdateReadinessSettingsFormType;
use App\Module\Readiness\Form\UpdateReadinessSettingsRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/readiness',
    name: 'app_readiness_settings',
    methods: ['GET', 'POST'],
)]
final class EditReadinessSettingsController extends AppController
{
    public function __construct(
        private readonly UpdateReadinessSettingsHandler $updateSettings,
        private readonly ShowReadinessHandler $showReadiness,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $data = UpdateReadinessSettingsRequest::fromProject($project);
        $form = $this->createForm(UpdateReadinessSettingsFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($this->updateSettings)(new UpdateReadinessSettingsCommand($project, $data->showGuide));
            $this->addFlash('success', $this->translator->trans('readiness.settings.flash.saved'));

            return $this->redirectToRoute('app_readiness_settings', ['id' => (string) $project->id]);
        }

        return $this->renderFormResponse('@Readiness/edit_readiness_settings.html.twig', $form, [
            'project' => $project,
            'discovery' => ($this->showReadiness)(new ShowReadinessCommand($project))->readiness->row('repository'),
        ]);
    }
}
