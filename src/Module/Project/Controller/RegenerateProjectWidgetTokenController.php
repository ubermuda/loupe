<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Command\RegenerateProjectWidgetTokenCommand;
use App\Module\Project\Command\RegenerateProjectWidgetTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('regenerate-project-widget-token')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/widget-token/regenerate',
    name: 'app_project_widget_token_regenerate',
    methods: ['POST'],
)]
class RegenerateProjectWidgetTokenController extends AppController
{
    public function __construct(
        private readonly RegenerateProjectWidgetTokenHandler $regenerateProjectWidgetTokenHandler,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $raw = ($this->regenerateProjectWidgetTokenHandler)(new RegenerateProjectWidgetTokenCommand($project));
        $this->addFlash('minted_widget_token', $raw);

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id]);
    }
}
