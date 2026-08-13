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
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

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
        ['name' => 'document_revise', 'descriptionKey' => 'project.connect.tool.document_revise'],
        ['name' => 'document_rename', 'descriptionKey' => 'project.connect.tool.document_rename'],
        ['name' => 'document_archive', 'descriptionKey' => 'project.connect.tool.document_archive'],
        ['name' => 'document_unarchive', 'descriptionKey' => 'project.connect.tool.document_unarchive'],
        ['name' => 'document_set_tags', 'descriptionKey' => 'project.connect.tool.document_set_tags'],
        ['name' => 'document_set_references', 'descriptionKey' => 'project.connect.tool.document_set_references'],
        ['name' => 'document_highlight', 'descriptionKey' => 'project.connect.tool.document_highlight'],
        ['name' => 'document_get_review', 'descriptionKey' => 'project.connect.tool.document_get_review'],
        ['name' => 'document_reply_to_comment', 'descriptionKey' => 'project.connect.tool.document_reply_to_comment'],
        ['name' => 'document_mark_comment_addressed', 'descriptionKey' => 'project.connect.tool.document_mark_comment_addressed'],
        ['name' => 'tag_list', 'descriptionKey' => 'project.connect.tool.tag_list'],
        ['name' => 'site_review_get', 'descriptionKey' => 'project.connect.tool.site_review_get'],
        ['name' => 'site_review_mark_comment_addressed', 'descriptionKey' => 'project.connect.tool.site_review_mark_comment_addressed'],
    ];

    /** @var array<string, string> tool name => the flag that must be on to advertise it */
    private const array GATED_TOOLS = ['document_highlight' => 'review.highlights.enabled'];

    public function __construct(
        private readonly FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.mcp.server_name')]
        private readonly string $mcpServerName,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $tools = array_values(array_filter(
            self::TOOLS,
            fn (array $tool): bool => !isset(self::GATED_TOOLS[$tool['name']])
                || $this->featureFlags->isEnabled(self::GATED_TOOLS[$tool['name']]),
        ));

        return $this->render('@Project/connect_agent.html.twig', [
            'project' => $project,
            'tools' => $tools,
            'mcpServerName' => $this->mcpServerName,
        ]);
    }
}
