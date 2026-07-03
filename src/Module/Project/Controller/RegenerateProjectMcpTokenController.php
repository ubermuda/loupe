<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Command\RegenerateProjectMcpTokenCommand;
use App\Module\Project\Command\RegenerateProjectMcpTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('regenerate-project-mcp-token')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/mcp-token/regenerate',
    name: 'app_project_mcp_token_regenerate',
    methods: ['POST'],
)]
class RegenerateProjectMcpTokenController extends AppController
{
    public function __construct(
        private readonly RegenerateProjectMcpTokenHandler $regenerateProjectMcpTokenHandler,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $raw = ($this->regenerateProjectMcpTokenHandler)(new RegenerateProjectMcpTokenCommand($project));
        $this->addFlash('minted_mcp_token', $raw);

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id]);
    }
}
