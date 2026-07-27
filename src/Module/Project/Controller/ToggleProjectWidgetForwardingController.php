<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Command\ToggleProjectWidgetForwardingCommand;
use App\Module\Project\Command\ToggleProjectWidgetForwardingHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('toggle-project-widget-forwarding')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/widget-token/forwarding',
    name: 'app_project_widget_forwarding_toggle',
    methods: ['POST'],
)]
class ToggleProjectWidgetForwardingController extends AppController
{
    public function __construct(
        private readonly ToggleProjectWidgetForwardingHandler $toggleProjectWidgetForwardingHandler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        try {
            $enabled = ($this->toggleProjectWidgetForwardingHandler)(new ToggleProjectWidgetForwardingCommand($project));
            $this->addFlash('success', $this->translator->trans($enabled
                ? 'project.connect.forwarding.flash.enabled'
                : 'project.connect.forwarding.flash.disabled'));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id]);
    }
}
