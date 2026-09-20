<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Command\ListAdvertisedToolsCommand;
use App\Module\Project\Command\ListAdvertisedToolsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Form\UpdateProjectAllowedOriginsFormType;
use App\Module\Project\Form\UpdateProjectAllowedOriginsRequest;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/connect',
    name: 'app_project_connect',
    methods: ['GET'],
)]
class ConnectAgentController extends AppController
{
    public const string ALLOWED_ORIGINS_FORM = 'allowedOriginsForm';

    public function __construct(
        private readonly ListAdvertisedToolsHandler $listAdvertisedTools,

        #[Autowire(param: 'app.mcp.server_name')]
        private readonly string $mcpServerName,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        return $this->render('@Project/connect_agent.html.twig', [
            'project' => $project,
            'tools' => ($this->listAdvertisedTools)(new ListAdvertisedToolsCommand())->tools,
            'mcpServerName' => $this->mcpServerName,
            'allowedOriginsForm' => $this->getInjectedFormView($request, self::ALLOWED_ORIGINS_FORM)
                ?? $this->createForm(UpdateProjectAllowedOriginsFormType::class, UpdateProjectAllowedOriginsRequest::fromProject($project), [
                    'action' => $this->generateUrl('app_project_allowed_origins_update', ['id' => (string) $project->id]),
                ])->createView(),
        ]);
    }
}
