<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Command\MintProjectMcpTokenCommand;
use App\Module\Project\Command\MintProjectMcpTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('mint-project-mcp-token')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/site-review/sites/{id:project}/mcp-token',
    name: 'app_project_mcp_token_mint',
    methods: ['POST'],
)]
class MintProjectMcpTokenController extends AppController
{
    public function __construct(
        private readonly MintProjectMcpTokenHandler $mintProjectMcpTokenHandler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        try {
            $raw = ($this->mintProjectMcpTokenHandler)(new MintProjectMcpTokenCommand($project));
            $this->addFlash('success', sprintf(
                '%s %s',
                $this->translator->trans('project.mcp_token.flash_minted'),
                $raw,
            ));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_site_review_site', ['id' => (string) $project->id]);
    }
}
