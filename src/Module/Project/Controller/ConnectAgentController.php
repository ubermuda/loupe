<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Mcp\AdvertisedTools;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    public function __construct(
        private readonly AdvertisedTools $advertisedTools,

        #[Autowire(param: 'app.mcp.server_name')]
        private readonly string $mcpServerName,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $tools = $this->advertisedTools->enabled();

        return $this->render('@Project/connect_agent.html.twig', [
            'project' => $project,
            'tools' => $tools,
            'mcpServerName' => $this->mcpServerName,
        ]);
    }
}
