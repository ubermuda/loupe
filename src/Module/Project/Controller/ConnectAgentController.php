<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
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
    /**
     * The real MCP tools exposed to the connected agent, in the order they
     * appear in the "Agent tools" section (handoff §5). Each description is a
     * translation key so the copy stays in the message catalog.
     *
     * @var list<array{name: string, descriptionKey: string}>
     */
    private const array TOOLS = [
        ['name' => 'create_document', 'descriptionKey' => 'project.connect.tool.create_document'],
        ['name' => 'list_documents', 'descriptionKey' => 'project.connect.tool.list_documents'],
        ['name' => 'get_document', 'descriptionKey' => 'project.connect.tool.get_document'],
        ['name' => 'get_review', 'descriptionKey' => 'project.connect.tool.get_review'],
        ['name' => 'revise_document', 'descriptionKey' => 'project.connect.tool.revise_document'],
        ['name' => 'get_site_review', 'descriptionKey' => 'project.connect.tool.get_site_review'],
        ['name' => 'address_site_review_comments', 'descriptionKey' => 'project.connect.tool.address_site_review_comments'],
    ];

    public function __construct(
        #[Autowire(param: 'app.mcp.server_name')]
        private readonly string $mcpServerName,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('@Project/connect_agent.html.twig', [
            'project' => $project,
            'tools' => self::TOOLS,
            'mcpServerName' => $this->mcpServerName,
        ]);
    }
}
