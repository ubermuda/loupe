<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Command\MintProjectWidgetTokenCommand;
use App\Module\Project\Command\MintProjectWidgetTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('mint-project-widget-token')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/widget-token',
    name: 'app_project_widget_token_mint',
    methods: ['POST'],
)]
class MintProjectWidgetTokenController extends AppController
{
    public function __construct(
        private readonly MintProjectWidgetTokenHandler $mintProjectWidgetTokenHandler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        try {
            $raw = ($this->mintProjectWidgetTokenHandler)(new MintProjectWidgetTokenCommand($project));
            $this->addFlash('minted_widget_token', $raw);
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id]);
    }
}
