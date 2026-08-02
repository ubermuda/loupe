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
     * appear in the "Agent tools" section. Each description is a translation
     * key so the copy stays in the message catalog.
     *
     * @var list<array{name: string, descriptionKey: string}>
     */
    private const array TOOLS = [
        ['name' => 'document_create', 'descriptionKey' => 'project.connect.tool.document_create'],
        ['name' => 'document_list', 'descriptionKey' => 'project.connect.tool.document_list'],
        ['name' => 'document_get', 'descriptionKey' => 'project.connect.tool.document_get'],
        ['name' => 'document_get_review', 'descriptionKey' => 'project.connect.tool.document_get_review'],
        ['name' => 'document_revise', 'descriptionKey' => 'project.connect.tool.document_revise'],
        ['name' => 'site_review_get', 'descriptionKey' => 'project.connect.tool.site_review_get'],
        ['name' => 'site_review_mark_comment_addressed', 'descriptionKey' => 'project.connect.tool.site_review_mark_comment_addressed'],
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
